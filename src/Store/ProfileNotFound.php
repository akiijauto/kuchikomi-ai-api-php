<?php

declare(strict_types=1);

namespace App\Store;

/**
 * ProfileNotFound は public.profiles に該当する行が無いときに投げる。
 */
final class ProfileNotFound extends \RuntimeException
{
}
