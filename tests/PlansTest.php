<?php

declare(strict_types=1);

namespace App\Tests;

use App\Plans;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Plans::class)]
final class PlansTest extends TestCase
{
    public function test上限はプランごとに決まる(): void
    {
        self::assertSame(5, Plans::limit('free'));
        self::assertSame(300, Plans::limit('pro'));
    }

    public function test未知のプランはfreeと同じ扱いになる(): void
    {
        // 他の3実装がそうしている。ここだけ例外を投げると挙動が食い違う。
        self::assertSame(5, Plans::limit('enterprise'));
        self::assertSame(5, Plans::limit(''));
    }

    public function test集計月はUTCで切る(): void
    {
        // 日本時間の2月1日0時30分は、UTCではまだ1月31日。
        // 実行環境のタイムゾーンで切ってしまうと、同じ利用者が
        // 別々の月として数えられ、3実装で答えが変わる。
        $jst = new \DateTimeImmutable('2026-02-01 00:30:00', new \DateTimeZone('Asia/Tokyo'));
        self::assertSame('2026-01', Plans::currentMonthKey($jst));
    }

    public function test集計月はUTCの月初で切り替わる(): void
    {
        $utc = new \DateTimeImmutable('2026-02-01 00:00:00', new \DateTimeZone('UTC'));
        self::assertSame('2026-02', Plans::currentMonthKey($utc));

        $justBefore = new \DateTimeImmutable('2026-01-31 23:59:59', new \DateTimeZone('UTC'));
        self::assertSame('2026-01', Plans::currentMonthKey($justBefore));
    }

    public function testfreeプランの上限文言はアップグレードを案内する(): void
    {
        $message = Plans::limitMessage('free', 5);
        self::assertStringContainsString('5件', $message);
        self::assertStringContainsString('プロプラン', $message);
    }

    public function test有料プランの上限文言はアップグレードを案内しない(): void
    {
        $message = Plans::limitMessage('pro', 300);
        self::assertStringContainsString('300件', $message);
        self::assertStringNotContainsString('プロプラン', $message);
    }
}
