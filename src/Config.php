<?php

declare(strict_types=1);

namespace App;

/**
 * サーバの設定。すべて環境変数から作る(読み込みはここに集約する)。
 *
 * Go版(internal/app/server.go の Config、cmd/api/main.go の環境変数読み取り)と
 * 対応する。ただしGo版の Config は Addr/JWTSecret/PublicDir/DemoMode しか持たず、
 * DATABASE_URL・ANTHROPIC_API_KEY・ANTHROPIC_MODEL は main.go がその場で
 * os.Getenv して store.Open() / newGenerator() に直接渡していた。
 * PHP版はリクエストのたびに(CGI的に)このクラスから作り直す構成にするため、
 * 必要な値を1か所にまとめておく。
 *
 * JWT_SECRET が空のときに起動時点で落とす点はGo版よりこちらの方が安全に倒した
 * 判断: Go版はハンドラの中で毎回チェックしていた(server.go の withAuth)。
 * 「鍵が無ければそもそも動かさない」ほうが設定漏れに早く気づける。
 */
final class Config
{
    /** firebase/php-jwt 7系が HS256 に要求する鍵の最小長。 */
    private const MIN_JWT_SECRET_BYTES = 32;

    public function __construct(
        public readonly string  $databaseUrl,
        public readonly string  $jwtSecret,
        public readonly int     $port,
        public readonly bool    $demoMode,
        public readonly ?string $anthropicApiKey,
        public readonly string  $anthropicModel,
    ) {
    }

    /**
     * 環境変数から作る。JWT_SECRET が未設定/空なら \RuntimeException を投げる(起動時に落とす)。
     *
     * @param array<string, string>|null $env 省略時は $_ENV・$_SERVER・getenv() を見る。
     *                                         テストでは差し替えて環境変数汚染を避ける。
     */
    public static function fromEnv(?array $env = null): self
    {
        $get = static function (string $name) use ($env): ?string {
            if ($env !== null) {
                return $env[$name] ?? null;
            }
            $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);

            return $value === false || $value === null || $value === '' ? null : (string) $value;
        };

        $jwtSecret = $get('JWT_SECRET');
        if ($jwtSecret === null) {
            // ハンドラの中で気づく形にすると「ログインが必要です」に化けて、
            // 設定漏れなのか本当に未ログインなのか追えなくなる。起動できないほうが早く気づける。
            throw new \RuntimeException('JWT_SECRET が未設定です');
        }

        // 鍵の長さもここで見る。
        //
        // firebase/php-jwt 7系は HS256 に 32バイト以上の鍵を要求し、
        // 短いと署名時も検証時も DomainException を投げる。
        // JwtVerifier はトークン検証の失敗をすべて 401 にまとめているので、
        // ここで弾かないと「鍵が短い」が「トークンが無効です」に化けて、
        // 全リクエストが 401 になるのに原因がサーバー設定側だと分からなくなる。
        //
        // Go版・Rails版・Next.js版はこの制約を持たないため、
        // **3実装が受け付ける短い鍵を、PHP版だけが拒む**という違いが出る。
        // 揃えるほうへ倒さず、起動時に落とすほうを選んだ。
        // 実運用の鍵は Terraform が48バイトで生成しているので影響しない。
        if (strlen($jwtSecret) < self::MIN_JWT_SECRET_BYTES) {
            throw new \RuntimeException(sprintf(
                'JWT_SECRET が短すぎます(%dバイト)。HS256 には %d バイト以上が必要です',
                strlen($jwtSecret),
                self::MIN_JWT_SECRET_BYTES
            ));
        }

        $anthropicApiKey = $get('ANTHROPIC_API_KEY');

        // PORT は待受アドレスの決定には使わない(このPHP実装はビルトインサーバー/
        // php-fpmの起動オプションでポートを決める。Dockerfileの `php -S 0.0.0.0:${PORT}`
        // を参照)。それでもフィールドとして持つのは、Go版Configとの対応を保ち、
        // 将来アプリ内から参照する用途(起動ログ表示等)に備えるため。
        $port = (int) ($get('PORT') ?? '3000');

        return new self(
            databaseUrl: $get('DATABASE_URL') ?? '',
            jwtSecret: $jwtSecret,
            port: $port,
            // 文字列 "1" のときだけ有効。Go版の `os.Getenv("DEMO_MODE") == "1"` と同じ判断で、
            // "true"/"yes" 等の表記ゆれを受け付けない(暗黙の緩さを持ち込まない)。
            demoMode: $get('DEMO_MODE') === '1',
            anthropicApiKey: $anthropicApiKey,
            // 空なら ClaudeGenerator 自身の既定値(DEFAULT_MODEL)に委ねる。
            // ここで既定モデル名を重複して持たない。
            anthropicModel: $get('ANTHROPIC_MODEL') ?? '',
        );
    }
}
