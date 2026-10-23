<?php
declare(strict_types=1);
namespace App\Reply;

final class OfflineCurlFixture
{
    public static array $replies = [];
    public static int $calls = 0;
}
function curl_init(string $url): object { return new \stdClass(); }
function curl_setopt_array(object $handle, array $options): bool { return true; }
function curl_exec(object $handle): string
{
    OfflineCurlFixture::$calls++;
    return json_encode(['content'=>[['type'=>'text','text'=>json_encode(['replies'=>OfflineCurlFixture::$replies], JSON_THROW_ON_ERROR)]]], JSON_THROW_ON_ERROR);
}
function curl_errno(object $handle): int { return 0; }
function curl_error(object $handle): string { return ''; }
function curl_getinfo(object $handle, int $option): int { return 200; }
function curl_close(object $handle): void {}
