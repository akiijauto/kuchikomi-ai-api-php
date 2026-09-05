<?php

declare(strict_types=1);

namespace App;

/**
 * プランごとの上限。
 *
 * ここにある数字は画面表示とエラー文言のための複製で、
 * 上限の強制そのものは DB 側（plan_limits テーブルと increment_usage 関数）が正本。
 * アプリ側の数字がずれても実際の制限は破られないが、
 * 利用者に見せる文言だけが食い違うことになるので、変えるときは両方直す。
 *
 * Next.js版 web/src/lib/plans.ts、Rails版 Plans、Go版 internal/app/plans.go と同じ値。
 */
final class Plans
{
    /** @var array<string,int> */
    private const LIMITS = ['free' => 5, 'pro' => 300];

    /** 未知のプラン名は free と同じ扱いにする（Go版・Rails版と揃える）。 */
    public static function limit(string $plan): int
    {
        return self::LIMITS[$plan] ?? self::LIMITS['free'];
    }

    /**
     * 利用回数の集計単位（YYYY-MM）。
     *
     * UTC で切るのが要点。実行環境のタイムゾーンで切ると、
     * 同じ利用者が別々の月として数えられてしまい、3実装で答えが変わる。
     */
    public static function currentMonthKey(?\DateTimeImmutable $now = null): string
    {
        $now ??= new \DateTimeImmutable('now');

        return $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m');
    }

    public static function limitMessage(string $plan, int $limit): string
    {
        if ($plan === 'free') {
            return sprintf(
                '今月の無料利用回数(%d件)の上限に達しました。プロプランへのアップグレードをご検討ください',
                $limit
            );
        }

        return sprintf('今月の利用回数(%d件)の上限に達しました', $limit);
    }
}
