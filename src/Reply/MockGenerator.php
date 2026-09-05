<?php

declare(strict_types=1);

namespace App\Reply;

use App\Store\Profile;

/**
 * APIキーが無い/使わない設定のときに使う生成器。常に mock=true を返す。
 *
 * Go版の Mock と同じ方針:「動かないから止まる」のではなく
 * 「経路は通るが中身はデモ」にしておくと、鍵の無いテスト環境やCIでも
 * 認証・上限・レスポンス形式まで通して確かめられる(外部通信は一切しない)。
 */
final class MockGenerator implements Generator
{
    public function generate(Profile $profile, Review $review): Result
    {
        $sig = $profile->signature !== '' ? "\n" . $profile->signature : '';

        if ($review->rating <= 2) {
            $replies = [
                new ReplyItem(
                    '誠実な謝罪(デモ)',
                    'この度はご不快な思いをさせてしまい、誠に申し訳ございませんでした。'
                        . 'いただいたご指摘を真摯に受け止め、スタッフ一同サービスの改善に努めてまいります。' . $sig,
                ),
                new ReplyItem(
                    '改善姿勢を強調(デモ)',
                    '貴重なご意見をありがとうございます。ご指摘いただいた点について早急に見直しを行っております。'
                        . 'もし機会をいただけましたら、改善した' . $profile->storeName . 'をご体験いただけますと幸いです。' . $sig,
                ),
                new ReplyItem(
                    '簡潔(デモ)',
                    'この度は申し訳ございませんでした。いただいたお言葉を改善に活かしてまいります。' . $sig,
                ),
            ];
        } else {
            $replies = [
                new ReplyItem(
                    '標準(デモ)',
                    'この度はご来店と温かい口コミをありがとうございます。お楽しみいただけたようで、'
                        . 'スタッフ一同大変嬉しく思います。またのご来店を心よりお待ちしております。' . $sig,
                ),
                new ReplyItem(
                    '再来店を促す(デモ)',
                    '嬉しい口コミをありがとうございます!' . $profile->storeName
                        . 'では季節ごとに新しいメニューもご用意しております。次回のご来店もぜひお楽しみにいらしてください。' . $sig,
                ),
                new ReplyItem(
                    '簡潔(デモ)',
                    'ご来店と口コミ投稿、ありがとうございます。またお会いできる日を楽しみにしております。' . $sig,
                ),
            ];
        }

        return new Result($replies, true);
    }
}
