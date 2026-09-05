<?php

declare(strict_types=1);

namespace App\Tests\Fake;

use App\Store\UsageCounter;

/**
 * テスト用の差し替え。
 *
 * 上限超過や接続失敗は、実物のDBで再現しようとすると
 * 5回叩いてから6回目を見る、といった手順が要る。
 * ここでは「その状況になったときハンドラが何を返すか」だけを見たいので差し替える。
 */
final class FakeUsageCounter implements UsageCounter
{
    /** @var list<array{string, string}> 呼ばれた引数の記録 */
    public array $calls = [];

    public function __construct(
        private readonly int $returns = 1,
        private readonly ?\Throwable $throws = null,
    ) {
    }

    public function increment(string $userId, string $month): int
    {
        $this->calls[] = [$userId, $month];

        if ($this->throws !== null) {
            throw $this->throws;
        }

        return $this->returns;
    }
}
