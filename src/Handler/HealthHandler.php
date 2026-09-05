<?php

declare(strict_types=1);

namespace App\Handler;

use App\Http\Request;
use App\Http\Response;

/**
 * 死活監視。
 *
 * Next.js版・Rails版・Go版と同じく、認証基盤にもDBにも依存させない。
 * ここがDBを見に行くと「アプリは生きているがDBが不調」のときに
 * コンテナごと落とされ、どちらが壊れているのか切り分けられなくなる。
 */
final class HealthHandler
{
    public function __construct(private readonly float $startedAt)
    {
    }

    public function handle(Request $request): Response
    {
        return Response::json(200, [
            'status' => 'ok',
            'uptime' => round(microtime(true) - $this->startedAt, 3),
        ]);
    }
}
