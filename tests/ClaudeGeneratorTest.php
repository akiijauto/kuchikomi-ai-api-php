<?php

declare(strict_types=1);

namespace App\Tests;

use App\Reply\ClaudeGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * 外部へ通信する部分は試さない（APIキーも課金も要るため）。
 * ここで押さえるのは「他の実装とずれていないか」だけ。
 */
#[CoversClass(ClaudeGenerator::class)]
final class ClaudeGeneratorTest extends TestCase
{
    public function test既定モデルは他の3実装と同じ(): void
    {
        // ここがずれると、同じ入力に対して実装ごとに違う返信が返り、
        // 「言語の違い」なのか「設定の違い」なのか分からなくなる。
        // Next.js版・Rails版・Go版のいずれも claude-sonnet-4-6。
        $default = (new \ReflectionClass(ClaudeGenerator::class))
            ->getConstant('DEFAULT_MODEL');

        self::assertSame('claude-sonnet-4-6', $default);
    }

    public function test引数を省略したときだけ既定モデルが効く(): void
    {
        // PHPの既定引数は「省略したとき」にしか効かない。
        // 空文字を渡すと空のまま通ってしまい、モデル名が空のまま
        // APIを叩くことになる。Kernelが空なら引数ごと省略しているのは
        // このため。その前提が崩れていないことを確かめる。
        $model = static function (ClaudeGenerator $g): string {
            $p = (new \ReflectionClass($g))->getProperty('model');

            return (string) $p->getValue($g);
        };

        self::assertSame('claude-sonnet-4-6', $model(new ClaudeGenerator('dummy-key')));
        self::assertSame('', $model(new ClaudeGenerator('dummy-key', '')));
    }
}
