<?php

declare(strict_types=1);

namespace App\Store;

/**
 * Profile は public.profiles の1行を表す値オブジェクト。
 *
 * スキーマ側(01_schema.sql)で全列に not null default が付いているため、
 * PHP側でNULLを受け取る必要がない(Go版と同じ前提)。
 */
final class Profile
{
    public function __construct(
        public readonly string $storeName,
        public readonly string $industry,
        public readonly string $tone,
        public readonly string $signature,
        public readonly string $plan,
    ) {
    }
}
