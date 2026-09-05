<?php

declare(strict_types=1);

namespace App\Reply;

/**
 * 生成結果。
 * mock は「APIキーが無い/使わない設定なのでデモ返信を返した」ことを示す
 * (Go版 Result の Mock フィールドと同じ役割)。
 */
final class Result
{
    /**
     * @param ReplyItem[] $replies
     */
    public function __construct(
        public readonly array $replies,
        public readonly bool $mock,
    ) {
    }
}
