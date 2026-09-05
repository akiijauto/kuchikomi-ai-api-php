<?php

declare(strict_types=1);

namespace App\Store;

/**
 * LimitExceeded は今月の利用回数が plan_limits.monthly_limit に達しているときに投げる。
 *
 * DB関数 public.increment_usage が SQLSTATE 'P0001' で返した例外に対応する
 * (01_schema.sql の raise exception ... using errcode = 'P0001' を参照)。
 */
final class LimitExceeded extends \RuntimeException
{
}
