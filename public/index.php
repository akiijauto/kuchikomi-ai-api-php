<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Config;
use App\Http\Request;
use App\Http\Response;
use App\Kernel;

// 薄く保つ: 設定を読み、Kernelに渡し、送るだけ。
// ルーティング表(/api/health・/api/generate・/api/demo/token)の組み立ては
// Kernel::registerRoutes() の役目であって、ここでは行わない。

try {
    $config = Config::fromEnv();
} catch (\RuntimeException $e) {
    // JWT_SECRET未設定など、設定漏れによる起動失敗。
    // 「ログインが必要です」に化けさせず、原因が追えるようログにだけ残す
    // (Go版main.goが起動時にログを出して落ちるのと同じ判断)。
    error_log('起動できませんでした: ' . $e->getMessage());
    Response::error(500, 'サーバー設定に誤りがあります')->send();
    exit(1);
}

$kernel = new Kernel($config);
$response = $kernel->handle(Request::fromGlobals());
$response->send();
