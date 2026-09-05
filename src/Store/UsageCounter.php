<?php

declare(strict_types=1);

namespace App\Store;

/**
 * 利用回数の加算。
 *
 * ハンドラはこの形にだけ依存する。実装（UsageStore）はDBの関数を呼ぶだけで、
 * 上限の判定ロジックを持たない。テストでは上限超過や接続失敗を
 * 差し替えで再現できる。
 */
interface UsageCounter
{
    /**
     * 加算後の件数を返す。
     *
     * 上限超過は LimitExceeded、プランが決められない場合は PlanNotFound を投げる。
     */
    public function increment(string $userId, string $month): int;
}
