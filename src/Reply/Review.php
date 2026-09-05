<?php

declare(strict_types=1);

namespace App\Reply;

/**
 * 入力された口コミ1件。
 * Go版 internal/reply/reply.go の Review 構造体と同じ役割
 * (テキストと星の2値だけで生成できるようにするため)。
 */
final class Review
{
    public function __construct(
        public readonly string $text,
        public readonly int $rating,
    ) {
    }
}
