<?php

declare(strict_types=1);

namespace App\Store;

/**
 * StoreError はデータベース層で起きた、それ以外の分類に当てはまらない失敗を表す。
 *
 * increment_usage が返す SQLSTATE のうち P0001(上限超過)・P0002(プラン不明)は
 * それぞれ専用の例外(LimitExceeded / PlanNotFound)にするが、それ以外の
 * PDOException(接続断・SQL構文誤りなど)はすべてこれに包んで投げる。
 * 呼び出し側(Http層)は「業務上のエラー2種」と「それ以外の失敗」だけを
 * 区別できればよく、PDOExceptionという実装詳細を知る必要がなくなる。
 */
final class StoreError extends \RuntimeException
{
}
