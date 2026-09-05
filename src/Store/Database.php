<?php

declare(strict_types=1);

namespace App\Store;

/**
 * Database は DATABASE_URL(postgres://user:pass@host:port/db 形式)から PDO を作る。
 *
 * PDOのpgsqlドライバは "postgres://..." というURL形式のDSNをそのままでは
 * 受け付けない(pgsql:host=...;port=...;dbname=... という独自形式が必要)ため、
 * parse_url() で一度分解してから組み立て直す。
 */
final class Database
{
    public static function connect(string $databaseUrl): \PDO
    {
        $parts = parse_url($databaseUrl);
        if ($parts === false || !isset($parts['host'], $parts['path'])) {
            throw new StoreError('DATABASE_URL の形式が不正です');
        }

        $host = $parts['host'];
        $port = $parts['port'] ?? 5432;
        $dbName = ltrim($parts['path'], '/');
        $user = isset($parts['user']) ? rawurldecode($parts['user']) : '';
        $password = isset($parts['pass']) ? rawurldecode($parts['pass']) : '';

        $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s', $host, $port, $dbName);

        // sslmode 等のクエリパラメータ(本番Supabase接続で必要になる)をDSNへ引き継ぐ。
        if (isset($parts['query'])) {
            parse_str($parts['query'], $query);
            if (isset($query['sslmode']) && is_string($query['sslmode'])) {
                $dsn .= ';sslmode=' . $query['sslmode'];
            }
        }

        try {
            return new \PDO($dsn, $user, $password, [
                // 例外モード必須: エラーを戻り値のfalseで見落とさないようにする。
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                // 呼び出し側(ProfileStore等)が列名でアクセスできるようにする。
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            ]);
        } catch (\PDOException $e) {
            throw new StoreError('データベースへ接続できませんでした: ' . $e->getMessage(), 0, $e);
        }
    }
}
