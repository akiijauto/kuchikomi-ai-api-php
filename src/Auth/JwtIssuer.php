<?php

declare(strict_types=1);

namespace App\Auth;

use Firebase\JWT\JWT;

/**
 * デモ画面用に、Supabaseが出すものと同じ形のトークンを作る。
 *
 * 本来の利用者はSupabaseでログインして得たトークンを持ってくるので、
 * 本番でこれを使うことはない(呼び出し口はDEMO_MODEのときだけ生える。
 * Kernel::registerRoutes() 参照)。Go版 internal/auth/auth.go の Issue と同じ形。
 */
final class JwtIssuer
{
    public function __construct(private readonly string $secret)
    {
    }

    public function issue(string $subject, int $ttlSeconds): string
    {
        $now = time();

        return JWT::encode([
            'sub' => $subject,
            // 単一値の aud は配列ではなく文字列にする。golang-jwt/v5 の
            // ClaimStrings は要素数1のとき文字列としてマーシャルするため、
            // Go版が出すトークンの形("aud":"authenticated")に合わせる。
            'aud' => 'authenticated',
            'iat' => $now,
            'exp' => $now + $ttlSeconds,
        ], $this->secret, 'HS256');
    }
}
