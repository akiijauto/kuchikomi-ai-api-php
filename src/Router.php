<?php

declare(strict_types=1);

namespace App;

use App\Http\Request;
use App\Http\Response;

/**
 * 「メソッド＋パス」の完全一致だけで引く素朴なルータ。
 *
 * Go版はルーティング用のライブラリを入れず net/http.ServeMux だけで済ませている
 * (server.goの冒頭コメント参照)。この規模でフレームワークを足す理由がないという
 * 判断をPHP側でも踏襲する。
 *
 * Go 1.22 の ServeMux はパスが一致してメソッドだけ違う場合に405を返すが、
 * このルータはそこまでは面倒を見ない(一致しなければ一律404)。
 * 「素朴なもの」という要求に対してその区別は過剰と判断した。
 */
final class Router
{
    /** @var array<string, callable(Request): Response> */
    private array $routes = [];

    /**
     * @param callable(Request): Response $handler
     */
    public function add(string $method, string $path, callable $handler): void
    {
        $this->routes[$this->key($method, $path)] = $handler;
    }

    public function dispatch(Request $request): Response
    {
        $handler = $this->routes[$this->key($request->method, $request->path)] ?? null;
        if ($handler === null) {
            return Response::error(404, '見つかりません');
        }

        return $handler($request);
    }

    private function key(string $method, string $path): string
    {
        return strtoupper($method) . ' ' . $path;
    }
}
