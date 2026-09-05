<?php

declare(strict_types=1);

namespace App\Store;

/**
 * UsageStore は public.usage_logs への加算をまとめる。
 *
 * UsageCounter を実装しておくことで、ハンドラ側はPDOを持つ具体実装ではなく
 * インターフェースに依存でき、DB無しのテストではダミー実装に差し替えられる。
 */
final class UsageStore implements UsageCounter
{
    public function __construct(private readonly \PDO $pdo)
    {
    }

    /**
     * 今月の利用回数を1つ増やし、増やした後の値を返す。
     *
     * 上限チェックをPHP側で「今の件数を読む→上限と比較する→書く」という3手順に
     * 分解しない。分解すると、同時に2本のリクエストが来たとき、両方が
     * 「読む」を済ませた直後の隙間に割り込んで、どちらも「まだ上限に達していない」
     * と判定してしまい、合計で上限を超えて加算できてしまう。
     * DB関数 public.increment_usage は上限の比較と加算を1つのSQL文の中で完結させる
     * ため、呼び出し側の実装言語やプロセス数に関わらず上限を超えることがない
     * (Go版・Rails版・Next.js版もすべて同じ関数を呼んでいるので、上限の挙動は
     * 実装によらず一致する)。
     *
     * @throws LimitExceeded 今月の上限に達しているとき(SQLSTATE P0001)
     * @throws PlanNotFound 上限を決められないとき(SQLSTATE P0002)
     * @throws StoreError それ以外のDB失敗
     */
    public function increment(string $userId, string $month): int
    {
        try {
            $this->pdo->beginTransaction();

            // increment_usage の中の auth.uid() 相当(request.jwt.claim.sub)が
            // 読む値をここで立てる。set_config の第3引数 true は
            // 「このトランザクションの中だけ有効(トランザクションローカル)」という
            // 意味で、コネクションプーリングで同じ接続が使い回されても、
            // このリクエストが立てた利用者IDが次の別リクエストへ漏れることがない。
            $stmt = $this->pdo->prepare(
                "select set_config('request.jwt.claim.sub', :user_id, true)"
            );
            $stmt->execute(['user_id' => $userId]);

            $stmt = $this->pdo->prepare('select public.increment_usage(:month) as count');
            $stmt->execute(['month' => $month]);
            $count = $stmt->fetchColumn();

            $this->pdo->commit();

            return (int) $count;
        } catch (\PDOException $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            // メッセージ文字列ではなくSQLSTATEで判定する。文言(USAGE_LIMIT_EXCEEDED等)
            // で判定すると、DB側がエラーメッセージの文言だけを変えた瞬間に静かに
            // 壊れる。PDOExceptionでは $e->errorInfo[0] がSQLSTATE。
            $sqlState = $e->errorInfo[0] ?? null;

            throw match ($sqlState) {
                'P0001' => new LimitExceeded('usage limit exceeded', 0, $e),
                'P0002' => new PlanNotFound('usage plan not found', 0, $e),
                default => new StoreError($e->getMessage(), 0, $e),
            };
        }
    }
}
