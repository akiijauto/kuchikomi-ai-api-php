<?php

declare(strict_types=1);

namespace App\Reply;

use App\Store\Profile;

/**
 * 返信文を作る。Go版の Generator インターフェースに相当する。
 */
interface Generator
{
    /**
     * 口コミへの返信案を作る。
     * 失敗時は \RuntimeException を投げる(部分的な結果を返してはならない)。
     */
    public function generate(Profile $profile, Review $review): Result;
}
