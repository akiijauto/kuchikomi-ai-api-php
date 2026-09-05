<?php

declare(strict_types=1);

namespace App\Store;

/**
 * PlanNotFound は利用者の上限を決められなかったときに投げる。
 *
 * DB関数 public.increment_usage が SQLSTATE 'P0002' で返した例外に対応する。
 * profiles行が無い・plan列がplan_limitsに存在しない、といったケースで発生する
 * (01_schema.sql の raise exception ... using errcode = 'P0002' を参照)。
 */
final class PlanNotFound extends \RuntimeException
{
}
