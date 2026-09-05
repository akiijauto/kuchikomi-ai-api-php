<?php

declare(strict_types=1);

namespace App\Store;

/**
 * プロフィールの読み出し。
 *
 * ハンドラが具体的な実装（PDOを持つ ProfileStore）ではなく、この形に依存する。
 * そうしないと、ハンドラを試すのに毎回PostgreSQLが要ることになり、
 * 「入力の検証が正しいか」を確かめたいだけのテストまでDB待ちになる。
 */
interface ProfileReader
{
    /** 見つからなければ ProfileNotFound を投げる。 */
    public function find(string $userId): Profile;

    /** デモ用の利用者行を用意する。何度呼んでも増えないこと。 */
    public function ensureDemoUser(string $userId, Profile $profile): void;
}
