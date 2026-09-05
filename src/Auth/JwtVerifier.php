<?php

declare(strict_types=1);

namespace App\Auth;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

/**
 * ログイン基盤(Supabase)が発行したJWTの検証を行う。
 *
 * Go版 internal/auth/auth.go の Verify と同じ判断をしている。
 * 「署名が正しいか」と「誰なのか(sub)」を取り出すだけ。
 */
final class JwtVerifier
{
    public function __construct(private readonly string $secret)
    {
    }

    /**
     * Authorization ヘッダ全体("Bearer xxx")を受け取り、sub(利用者ID)を返す。
     *
     * @throws AuthException トークンが無い・壊れている・期限切れ・別の鍵で署名、など
     */
    public function verifyBearer(?string $authorizationHeader): string
    {
        $token = self::bearerToken($authorizationHeader);
        if ($token === null) {
            throw new AuthException('認証トークンがありません');
        }

        try {
            // Key に 'HS256' を明示するのが要点で、飾りではない。
            // JWT::decode は渡された Key の algorithm とトークンヘッダの alg が
            // 一致するかを検証してから使う(vendor/firebase/php-jwt/src/JWT.php の
            // getKey/constantTimeEquals 参照)ため、ここで固定しておけば
            // トークンのヘッダに書かれた alg をライブラリが鵜呑みにする経路
            // (アルゴリズム混同攻撃。例: RS256用の公開鍵をHS256の共有鍵として
            // 誤用させる)が生まれない。受け入れる署名方式はこちらが決める、という
            // 形にしておく(Go版の jwt.WithValidMethods([]string{"HS256"}) と同じ意図)。
            $payload = JWT::decode($token, new Key($this->secret, 'HS256'));
        } catch (\Throwable) {
            // 壊れている・期限切れ・署名不一致・alg不一致などをすべて同じ扱いにする。
            // 理由を外へ漏らさない(AuthExceptionのdocコメント参照)。
            throw new AuthException('認証トークンが無効です');
        }

        $subject = $payload->sub ?? null;
        if (!is_string($subject) || $subject === '') {
            throw new AuthException('認証トークンにsubがありません');
        }

        return $subject;
    }

    /**
     * Authorization ヘッダから "Bearer " を取り除いた部分を取り出す。
     * 形式が違えば null を返す(呼び出し側の扱いを揃えるため、例外にはしない。
     * Go版 BearerToken と同じ判断)。
     */
    private static function bearerToken(?string $authorizationHeader): ?string
    {
        $prefix = 'Bearer ';
        if ($authorizationHeader === null || !str_starts_with($authorizationHeader, $prefix)) {
            return null;
        }

        $token = trim(substr($authorizationHeader, strlen($prefix)));

        return $token === '' ? null : $token;
    }
}
