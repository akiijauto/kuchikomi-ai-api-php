<?php

declare(strict_types=1);

namespace App\Http;

/**
 * 1レスポンス分のデータ。このAPIはJSONしか返さないので、本文は配列で持つ。
 *
 * Go版の writeJSON / errorBody に相当する(server.go)。
 */
final class Response
{
    /**
     * @param array<mixed> $body
     */
    private function __construct(
        private readonly int   $status,
        private readonly array $body,
    ) {
    }

    /**
     * @param array<mixed>|\JsonSerializable $body
     */
    public static function json(int $status, array|\JsonSerializable $body): self
    {
        $data = $body instanceof \JsonSerializable ? $body->jsonSerialize() : $body;

        return new self($status, (array) $data);
    }

    /** 本文を {"error": message} の形に固定する(Go版 errorBody と同じ形)。 */
    public static function error(int $status, string $message): self
    {
        return new self($status, ['error' => $message]);
    }

    public function status(): int
    {
        return $this->status;
    }

    /** @return array<mixed> */
    public function body(): array
    {
        return $this->body;
    }

    public function send(): void
    {
        http_response_code($this->status);
        header('Content-Type: application/json; charset=utf-8');
        // JSON_UNESCAPED_UNICODE: 日本語のエラーメッセージを \uXXXX に潰さない。
        // JSON_UNESCAPED_SLASHES: Go版のjson.Marshalもスラッシュをエスケープしないので合わせる。
        echo json_encode($this->body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
