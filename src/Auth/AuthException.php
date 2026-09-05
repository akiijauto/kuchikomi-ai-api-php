<?php

declare(strict_types=1);

namespace App\Auth;

/**
 * 認証に失敗したことを表す例外の総称。
 *
 * 「トークンが無い・壊れている・期限切れ・別の鍵で署名」のどれであったかは、
 * 呼び出し側にも利用者にも区別して返さない(攻撃者への手がかりを与えないため)。
 * Go版 internal/auth/auth.go の ErrUnauthorized と同じ判断。
 */
final class AuthException extends \RuntimeException
{
}
