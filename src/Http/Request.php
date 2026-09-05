<?php

declare(strict_types=1);

namespace App\Http;

/**
 * PHPのグローバル状態($_SERVER・php://input)から作る、1リクエスト分の素データ。
 *
 * Go版の *http.Request に相当するものをこの規模向けに切り出したもの。
 * わざわざ値オブジェクトにしているのは、ハンドラのテストで
 * $_SERVER を書き換えずに任意のリクエストを組み立てられるようにするため
 * (tests/Fake/* がPDO無しでハンドラを試せるのと同じ狙い)。
 */
final class Request
{
    /**
     * @param array<string, string> $headers キーはすべて小文字にする(呼び出し側に大小文字の違いを持ち込まない)
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array  $headers,
        public readonly string $body,
    ) {
    }

    public static function fromGlobals(): self
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            $path = '/';
        }

        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (!is_string($key) || !is_string($value)) {
                continue;
            }
            // PHPのCGI/SAPI慣習: リクエストヘッダは HTTP_FOO_BAR という形で$_SERVERに入る。
            if (str_starts_with($key, 'HTTP_')) {
                $name = strtolower(str_replace('_', '-', substr($key, 5)));
                $headers[$name] = $value;
            }
        }
        // Content-Type と Content-Length だけは HTTP_ 接頭辞が付かない(CGI仕様の例外)ので個別に拾う。
        if (isset($_SERVER['CONTENT_TYPE']) && is_string($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = $_SERVER['CONTENT_TYPE'];
        }
        if (isset($_SERVER['CONTENT_LENGTH']) && is_string($_SERVER['CONTENT_LENGTH'])) {
            $headers['content-length'] = $_SERVER['CONTENT_LENGTH'];
        }

        $body = file_get_contents('php://input');

        return new self($method, $path, $headers, $body === false ? '' : $body);
    }

    /** 大小文字を区別せずヘッダを引く。無ければ null。 */
    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }
}
