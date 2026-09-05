<?php

declare(strict_types=1);

namespace App\Store;

/**
 * ProfileStore は public.profiles への読み書きをまとめる。
 *
 * ProfileReader を実装しておくことで、ハンドラ側はPDOを持つ具体実装ではなく
 * インターフェースに依存でき、DB無しのテストではダミー実装に差し替えられる。
 */
final class ProfileStore implements ProfileReader
{
    public function __construct(private readonly \PDO $pdo)
    {
    }

    /**
     * 自分の行だけを読む。
     *
     * 本番のSupabaseでは行レベルセキュリティ(RLS)が最後の砦になるが、
     * この接続はテーブル所有者のロールで繋ぐためRLSは素通りする。
     * ここで id を条件に入れているのは飾りではない(Go版・Rails版と同じ判断)。
     *
     * @throws ProfileNotFound 該当する行が無いとき
     * @throws StoreError それ以外のDB失敗
     */
    public function find(string $userId): Profile
    {
        try {
            $stmt = $this->pdo->prepare(
                'select store_name, industry, tone, signature, plan
                   from public.profiles where id = :id'
            );
            $stmt->execute(['id' => $userId]);
            $row = $stmt->fetch();
        } catch (\PDOException $e) {
            throw new StoreError($e->getMessage(), 0, $e);
        }

        if ($row === false) {
            throw new ProfileNotFound("profile not found: {$userId}");
        }

        return new Profile(
            storeName: $row['store_name'],
            industry: $row['industry'],
            tone: $row['tone'],
            signature: $row['signature'],
            plan: $row['plan'],
        );
    }

    /**
     * デモ画面用の利用者とプロフィールを用意する。DEMO_MODE のときだけ呼ばれる。
     *
     * public.profiles の行は 01_schema.sql の on_auth_user_created トリガーが
     * auth.users への insert をきっかけに自動で作るので、ここで profiles を
     * insert しようとすると主キー重複になる。作るのではなく更新する。
     * auth.users 側は on conflict do nothing にして、何度呼んでも
     * 行が増えない(=このメソッドが冪等になる)ようにしている。
     *
     * @throws StoreError DB失敗
     */
    public function ensureDemoUser(string $userId, Profile $profile): void
    {
        try {
            $stmt = $this->pdo->prepare(
                'insert into auth.users (id, email) values (:id, :email)
                 on conflict (id) do nothing'
            );
            $stmt->execute(['id' => $userId, 'email' => $userId . '@example.test']);

            $stmt = $this->pdo->prepare(
                'update public.profiles
                    set store_name = :store_name,
                        industry = :industry,
                        tone = :tone,
                        signature = :signature
                  where id = :id'
            );
            $stmt->execute([
                'id' => $userId,
                'store_name' => $profile->storeName,
                'industry' => $profile->industry,
                'tone' => $profile->tone,
                'signature' => $profile->signature,
            ]);
        } catch (\PDOException $e) {
            throw new StoreError($e->getMessage(), 0, $e);
        }
    }
}
