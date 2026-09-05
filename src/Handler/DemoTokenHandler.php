<?php

declare(strict_types=1);

namespace App\Handler;

use App\Auth\JwtIssuer;
use App\Http\Request;
use App\Http\Response;
use App\Store\Profile;
use App\Store\ProfileReader;

/**
 * デモ画面用にトークンを発行する。
 *
 * 本来の利用者は Supabase でログインして得たトークンを持ってくる。
 * デモでは Supabase が無いので、同じ形のトークンをこちら側で作る。
 *
 * DEMO_MODE が有効なときだけ経路を生やす（Kernel 側で判定）。
 * 本番でこの経路が開いていると、誰でも利用枠を消費できてしまう。
 */
final class DemoTokenHandler
{
    /**
     * デモ用の固定利用者。
     *
     * Rails版・Go版と同じIDを使う。同じDBを見たときに行が増えないほうが、
     * 3実装が同じカウンタを共有していることを確かめやすい。
     */
    public const DEMO_USER_ID = '00000000-0000-4000-8000-000000000001';

    private const TTL_SECONDS = 900;

    public function __construct(
        private readonly ProfileReader $profiles,
        private readonly JwtIssuer $issuer,
    ) {
    }

    public function handle(Request $request): Response
    {
        try {
            $this->profiles->ensureDemoUser(self::DEMO_USER_ID, new Profile(
                storeName: 'デモ食堂',
                industry: '飲食店',
                tone: 'friendly',
                signature: '店主 デモ',
                plan: 'free',
            ));

            $token = $this->issuer->issue(self::DEMO_USER_ID, self::TTL_SECONDS);
        } catch (\Throwable $e) {
            // 例外にはトークンや接続文字列が混ざりうるので、種類だけ残す。
            error_log('デモ用トークンの発行に失敗: ' . $e::class);

            return Response::error(500, '生成に失敗しました。時間をおいて再度お試しください');
        }

        return Response::json(200, [
            'token' => $token,
            'expires_in' => self::TTL_SECONDS,
        ]);
    }
}
