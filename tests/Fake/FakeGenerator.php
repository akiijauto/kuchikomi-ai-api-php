<?php

declare(strict_types=1);

namespace App\Tests\Fake;

use App\Reply\Generator;
use App\Reply\ReplyItem;
use App\Reply\Result;
use App\Reply\Review;
use App\Store\Profile;

/**
 * テスト用の差し替え。外部への通信をしない。
 */
final class FakeGenerator implements Generator
{
    public ?Review $received = null;

    public function __construct(private readonly ?\Throwable $throws = null)
    {
    }

    public function generate(Profile $profile, Review $review): Result
    {
        $this->received = $review;

        if ($this->throws !== null) {
            throw $this->throws;
        }

        return new Result([new ReplyItem('polite', 'テスト用の返信')], true);
    }
}
