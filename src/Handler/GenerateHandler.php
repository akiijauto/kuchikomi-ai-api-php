<?php

declare(strict_types=1);

namespace App\Handler;

use App\Http\Request;
use App\Http\Response;
use App\Plans;
use App\Reply\Generator;
use App\Reply\ReplyItem;
use App\Reply\Review;
use App\Store\LimitExceeded;
use App\Store\PlanNotFound;
use App\Store\ProfileNotFound;
use App\Store\ProfileReader;
use App\Store\UsageCounter;

/**
 * クチコミへの返信案を作る。
 *
 * Next.js版 web/src/app/api/generate/route.ts の移植で、
 * ステータスコードと文言を Next.js版・Rails版・Go版に合わせてある。
 * 同じ入力に同じ答えを返すことがこの演習の眼目なので、文言を勝手に変えない。
 *
 * 依存を具体的なクラスではなくインターフェースで受けているのは、
 * 入力検証を確かめたいだけのテストにまでPostgreSQLを要求しないため。
 */
final class GenerateHandler
{
    /** 受け付ける本文の上限。これを超えるものは読まずに切る。 */
    private const MAX_BODY_BYTES = 1048576;

    private const INTERNAL_ERROR = '生成に失敗しました。時間をおいて再度お試しください';
    private const NEED_PROFILE = '先にお店のプロフィールを設定してください';
    private const BAD_SHAPE = '入力内容を確認してください';
    private const BAD_REQUEST = '不正なリクエストです';

    public function __construct(
        private readonly ProfileReader $profiles,
        private readonly UsageCounter $usage,
        private readonly Generator $generator,
    ) {
    }

    public function handle(Request $request, string $userId): Response
    {
        if (strlen($request->body) > self::MAX_BODY_BYTES) {
            return Response::error(400, self::BAD_REQUEST);
        }

        // 「JSONとして壊れている」と「JSONではあるが形が違う」を分けて返す。
        // 他の3実装が別の文言を返しているので、ここも合わせる。
        try {
            $decoded = json_decode($request->body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return Response::error(400, self::BAD_REQUEST);
        }

        $review = is_array($decoded) ? ($decoded['review'] ?? null) : null;
        if (!is_array($review)) {
            return Response::error(400, self::BAD_SHAPE);
        }

        $text = $review['reviewText'] ?? null;
        if (!is_string($text)) {
            return Response::error(400, self::BAD_SHAPE);
        }

        // 長さは strlen（バイト数）ではなく mb_strlen（文字数）で数える。
        // strlen だと「美味しい」が12バイトで12文字と判定され、
        // 文字数で数える TypeScript版・Ruby版と答えが食い違う。
        $length = mb_strlen($text);
        if ($length < 5 || $length > 2000) {
            return Response::error(400, self::BAD_SHAPE);
        }

        // 星は整数だけを受ける。5.0（浮動小数）や "5"（文字列）は弾く。
        // Go版が json.Number を Atoi で読んで同じ結果になり、
        // Ruby版も Integer であることを要求している。
        $rating = $review['rating'] ?? null;
        if (!is_int($rating) || $rating < 1 || $rating > 5) {
            return Response::error(400, self::BAD_SHAPE);
        }

        try {
            $profile = $this->profiles->find($userId);
        } catch (ProfileNotFound) {
            return Response::error(400, self::NEED_PROFILE);
        } catch (\Throwable $e) {
            $this->logFailure('プロフィールの取得に失敗', $e);

            return Response::error(500, self::INTERNAL_ERROR);
        }

        if ($profile->storeName === '') {
            return Response::error(400, self::NEED_PROFILE);
        }

        $limit = Plans::limit($profile->plan);

        // 上限チェックと加算はDB関数の中で原子的に行う。
        // ここで「読んでから書く」と、同時リクエストが上限を超えて通る隙間ができる。
        try {
            $used = $this->usage->increment($userId, Plans::currentMonthKey());
        } catch (LimitExceeded) {
            return Response::error(429, Plans::limitMessage($profile->plan, $limit));
        } catch (PlanNotFound) {
            // プロフィール行が無い・未知のプラン。DB側が加算を拒否している。
            return Response::error(400, self::NEED_PROFILE);
        } catch (\Throwable $e) {
            $this->logFailure('利用回数の加算に失敗', $e);

            return Response::error(500, self::INTERNAL_ERROR);
        }

        try {
            $result = $this->generator->generate($profile, new Review($text, $rating));
        } catch (\Throwable $e) {
            $this->logFailure('生成に失敗', $e);

            return Response::error(500, self::INTERNAL_ERROR);
        }

        return Response::json(200, [
            'replies' => array_map(
                static fn (ReplyItem $item): array => $item->toArray(),
                $result->replies
            ),
            'mock' => $result->mock,
            'usage' => ['used' => $used, 'limit' => $limit],
        ]);
    }

    /**
     * 失敗の記録。
     *
     * 例外メッセージには接続文字列やAPIキーが混ざりうるので、そのままは出さない。
     * 種類（クラス名）と、こちらで書いた説明だけを残す。
     */
    private function logFailure(string $what, \Throwable $e): void
    {
        error_log(sprintf('%s: %s', $what, $e::class));
    }
}
