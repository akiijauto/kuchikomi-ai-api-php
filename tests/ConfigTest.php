<?php

declare(strict_types=1);

namespace App\Tests;

use App\Config;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Config::class)]
final class ConfigTest extends TestCase
{
    /** @return array<string,string> */
    private static function env(array $overrides = []): array
    {
        return $overrides + [
            'JWT_SECRET' => str_repeat('a', 48),
            'DATABASE_URL' => 'postgresql://u:p@localhost:5432/app',
        ];
    }

    public function testJWT_SECRETが無ければ起動時に落ちる(): void
    {
        // ハンドラの中で気づく形にすると「ログインが必要です」に化けて、
        // 設定漏れなのか本当に未ログインなのか追えなくなる。
        $this->expectException(\RuntimeException::class);
        Config::fromEnv(['DATABASE_URL' => 'postgresql://u:p@localhost:5432/app']);
    }

    public function testJWT_SECRETが32バイト未満なら起動時に落ちる(): void
    {
        // firebase/php-jwt 7系は HS256 に32バイト以上を要求し、
        // 短いと検証時に例外を投げる。JwtVerifier はトークン検証の失敗を
        // すべて401にまとめているので、ここで弾かないと
        // 「鍵が短い」が「トークンが無効です」に化けて、
        // 全リクエストが401になるのに原因がサーバー側だと分からなくなる。
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/短すぎ/');

        Config::fromEnv(self::env(['JWT_SECRET' => str_repeat('a', 31)]));
    }

    public function testJWT_SECRETがちょうど32バイトなら通る(): void
    {
        $config = Config::fromEnv(self::env(['JWT_SECRET' => str_repeat('a', 32)]));

        self::assertSame(32, strlen($config->jwtSecret));
    }

    public function testDEMO_MODEは1のときだけ有効になる(): void
    {
        // 本番でこの経路が開いていると、誰でも利用枠を消費できてしまう。
        // 「設定されていれば有効」にすると DEMO_MODE=0 でも開いてしまう。
        self::assertTrue(Config::fromEnv(self::env(['DEMO_MODE' => '1']))->demoMode);
        self::assertFalse(Config::fromEnv(self::env(['DEMO_MODE' => '0']))->demoMode);
        self::assertFalse(Config::fromEnv(self::env(['DEMO_MODE' => 'true']))->demoMode);
        self::assertFalse(Config::fromEnv(self::env())->demoMode);
    }

    public function testAPIキーが無ければnullになる(): void
    {
        // 呼び出し側はこれを見てモックに切り替える。
        self::assertNull(Config::fromEnv(self::env())->anthropicApiKey);
    }

    public function test未設定のモデル名は空になり既定は生成器側が持つ(): void
    {
        // 既定モデル名をConfigとClaudeGeneratorの両方に書くと、片方だけ直して
        // ずれる。Configは「指定が無い」ことだけを伝え、既定値は生成器が持つ。
        //
        // なおPHPの既定引数は「引数を省略したとき」にしか効かないので、
        // 空文字をそのまま渡すと空のまま。Kernelは空なら引数ごと省略している。
        self::assertSame('', Config::fromEnv(self::env())->anthropicModel);
    }

    public function test指定したモデル名はそのまま渡る(): void
    {
        $config = Config::fromEnv(self::env(['ANTHROPIC_MODEL' => 'claude-opus-5']));

        self::assertSame('claude-opus-5', $config->anthropicModel);
    }
}
