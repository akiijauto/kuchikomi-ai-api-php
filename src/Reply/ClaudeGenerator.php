<?php

declare(strict_types=1);

namespace App\Reply;

use App\Store\Profile;
use JsonException;
use RuntimeException;

/**
 * Anthropic Messages API を curl で直接叩く生成器。
 *
 * Go版が anthropic-sdk-go というほぼ最小限の依存だけで書かれているのに合わせ、
 * PHP用の公式SDKには依存せず curl で直接呼ぶ。
 *
 * APIキーが空のときはこのクラスを作らない設計になっている(呼び出し側がMockを選ぶ)。
 * そのためコンストラクタは「キーがある」ことを前提にしてよい。
 */
final class ClaudeGenerator implements Generator
{
    private const API_URL = 'https://api.anthropic.com/v1/messages';
    private const ANTHROPIC_VERSION = '2023-06-01';

    // Go版 defaultModel と同じ既定値。3実装(Go/TypeScript/Ruby)で
    // 同じモデル・同じプロンプトにしておかないと、出力の違いが
    // 「言語の違い」なのか「設定の違い」なのか分からなくなる。
    private const DEFAULT_MODEL = 'claude-sonnet-4-6';

    // Go版 Claude.Generate の MaxTokens と同じ値。
    private const MAX_TOKENS = 2048;

    // 返信案の形。構造化出力(output_config.format)で形を保証させ、
    // 「JSONのつもりが散文だった」を実行時に持ち込まないようにする(Go版と同じ)。
    private const REPLY_SCHEMA = [
        'type' => 'object',
        'properties' => [
            'replies' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'label' => ['type' => 'string'],
                        'text' => ['type' => 'string'],
                    ],
                    'required' => ['label', 'text'],
                    'additionalProperties' => false,
                ],
            ],
        ],
        'required' => ['replies'],
        'additionalProperties' => false,
    ];

    public function __construct(
        private readonly string $apiKey,
        private readonly string $model = self::DEFAULT_MODEL,
        // 無期限に待たないための応答タイムアウト(秒)。接続タイムアウトは別途固定で持つ。
        private readonly int $timeoutSeconds = 30,
    ) {
    }

    public function generate(Profile $profile, Review $review): Result
    {
        $body = [
            'model' => $this->model,
            'max_tokens' => self::MAX_TOKENS,
            // 返信文の作成に長い推論は要らない。既定のまま投げると
            // 待ち時間とトークンを無駄に使う(Go版・Ruby版と同じ設定に揃えてある)。
            'thinking' => ['type' => 'disabled'],
            'output_config' => [
                'effort' => 'low',
                'format' => [
                    'type' => 'json_schema',
                    'schema' => self::REPLY_SCHEMA,
                ],
            ],
            'system' => [
                ['type' => 'text', 'text' => Prompt::system($profile)],
            ],
            'messages' => [
                ['role' => 'user', 'content' => [
                    ['type' => 'text', 'text' => Prompt::user($review)],
                ]],
            ],
        ];

        $response = $this->post($body);
        $text = $this->extractText($response);

        try {
            /** @var mixed $parsed */
            $parsed = json_decode(Prompt::trimJsonFence($text), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('生成結果を読み取れませんでした');
        }

        if (!is_array($parsed) || !isset($parsed['replies']) || !is_array($parsed['replies'])) {
            throw new RuntimeException('生成結果を読み取れませんでした');
        }

        if (count($parsed['replies']) === 0) {
            throw new RuntimeException('返信案が1件も返りませんでした');
        }

        $replies = [];
        foreach ($parsed['replies'] as $item) {
            if (
                !is_array($item)
                || !isset($item['label'], $item['text'])
                || !is_string($item['label'])
                || !is_string($item['text'])
            ) {
                throw new RuntimeException('生成結果を読み取れませんでした');
            }
            $replies[] = new ReplyItem($item['label'], $item['text']);
        }

        return new Result(Prompt::withSignature($replies, $profile->signature), false);
    }

    /**
     * Anthropic Messages API を呼び、デコード済みのレスポンス本文を返す。
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function post(array $body): array
    {
        try {
            $payload = json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        } catch (JsonException) {
            throw new RuntimeException('リクエストの作成に失敗しました');
        }

        $ch = curl_init(self::API_URL);
        if ($ch === false) {
            throw new RuntimeException('生成の呼び出しに失敗しました');
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            // 無期限に待たない(接続10秒・応答は $timeoutSeconds 秒で打ち切る)。
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'x-api-key: ' . $this->apiKey,
                'anthropic-version: ' . self::ANTHROPIC_VERSION,
            ],
        ]);

        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        // curlのエラー文言は接続・URL系のみで、APIキーやヘッダの中身は含まれない。
        $curlError = curl_error($ch);
        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0 || $raw === false) {
            throw new RuntimeException('生成の呼び出しに失敗しました: ' . $curlError);
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('生成結果を読み取れませんでした');
        }

        if (!is_array($decoded)) {
            throw new RuntimeException('生成結果を読み取れませんでした');
        }

        if ($statusCode !== 200) {
            $errorInfo = $decoded['error'] ?? null;
            $message = is_array($errorInfo) && is_string($errorInfo['message'] ?? null)
                ? $errorInfo['message']
                : '';
            throw new RuntimeException(sprintf('生成の呼び出しに失敗しました(status=%d): %s', $statusCode, $message));
        }

        return $decoded;
    }

    /**
     * レスポンスの content 配列から最初のテキストブロックを取り出す。
     *
     * @param array<string, mixed> $response
     */
    private function extractText(array $response): string
    {
        $content = $response['content'] ?? null;
        if (!is_array($content)) {
            throw new RuntimeException('生成結果が空でした');
        }

        foreach ($content as $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'text' && is_string($block['text'] ?? null)) {
                return $block['text'];
            }
        }

        throw new RuntimeException('生成結果が空でした');
    }
}
