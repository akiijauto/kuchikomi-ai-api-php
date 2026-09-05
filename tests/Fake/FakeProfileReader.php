<?php

declare(strict_types=1);

namespace App\Tests\Fake;

use App\Store\Profile;
use App\Store\ProfileNotFound;
use App\Store\ProfileReader;

/**
 * テスト用の差し替え。
 *
 * 実物（PDOを持つ ProfileStore）を使うとPostgreSQLが要るが、
 * ここで確かめたいのは「入力をどう検証するか」「失敗をどのステータスに翻訳するか」
 * であってSQLではない。DBの正しさは別のテストで見る。
 */
final class FakeProfileReader implements ProfileReader
{
    public ?Profile $profile;
    public ?\Throwable $throwOnFind;

    /** @var list<array{string, Profile}> */
    public array $ensured = [];

    public function __construct(?Profile $profile = null, ?\Throwable $throwOnFind = null)
    {
        $this->profile = $profile;
        $this->throwOnFind = $throwOnFind;
    }

    public function find(string $userId): Profile
    {
        if ($this->throwOnFind !== null) {
            throw $this->throwOnFind;
        }
        if ($this->profile === null) {
            throw new ProfileNotFound('見つかりません');
        }

        return $this->profile;
    }

    public function ensureDemoUser(string $userId, Profile $profile): void
    {
        $this->ensured[] = [$userId, $profile];
    }
}
