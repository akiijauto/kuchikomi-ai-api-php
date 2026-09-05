<?php

declare(strict_types=1);

namespace App\Tests;

use App\Handler\GenerateHandler;
use App\Http\Request;
use App\Http\Response;
use App\Store\LimitExceeded;
use App\Store\PlanNotFound;
use App\Store\Profile;
use App\Tests\Fake\FakeGenerator;
use App\Tests\Fake\FakeProfileReader;
use App\Tests\Fake\FakeUsageCounter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(GenerateHandler::class)]
final class GenerateHandlerTest extends TestCase
{
    private const USER_ID = '00000000-0000-4000-8000-000000000001';

    private static function profile(string $plan = 'free', string $storeName = 'テスト食堂'): Profile
    {
        return new Profile(
            storeName: $storeName,
            industry: '飲食店',
            tone: 'friendly',
            signature: '店主',
            plan: $plan,
        );
    }

    private static function request(string $body): Request
    {
        return new Request('POST', '/api/generate', [], $body);
    }

    /** 正常に通る組み合わせで組み立てる。壊したい部分だけ差し替えて使う。 */
    private static function handler(
        ?FakeProfileReader $profiles = null,
        ?FakeUsageCounter $usage = null,
        ?FakeGenerator $generator = null,
    ): GenerateHandler {
        return new GenerateHandler(
            $profiles ?? new FakeProfileReader(self::profile()),
            $usage ?? new FakeUsageCounter(1),
            $generator ?? new FakeGenerator(),
        );
    }

    // ── 壊れた入力と、形が違う入力を分ける ───────────────────────

    public function testJSONとして壊れていれば不正なリクエストと返す(): void
    {
        $response = self::handler()->handle(self::request('{"review":'), self::USER_ID);

        self::assertSame(400, $response->status());
        self::assertSame('不正なリクエストです', $response->body()['error']);
    }

    public function testJSONではあるが形が違えば別の文言を返す(): void
    {
        // 「壊れている」と「形が違う」を同じ文言にしてしまうと、
        // 送り手はどちらを直せばよいのか分からない。
        $response = self::handler()->handle(self::request('{"foo":1}'), self::USER_ID);

        self::assertSame(400, $response->status());
        self::assertSame('入力内容を確認してください', $response->body()['error']);
    }

    // ── 文字数の数え方 ────────────────────────────────

    public function test日本語5文字は通る(): void
    {
        // 「とても良い」はちょうど5文字。UTF-8では15バイトある。
        // ここが通り、かつ下の「2文字」が弾かれることで、文字数で数えていると言える。
        $body = json_encode(['review' => ['reviewText' => 'とても良い', 'rating' => 5]], JSON_THROW_ON_ERROR);
        $response = self::handler()->handle(self::request($body), self::USER_ID);

        self::assertSame(200, $response->status());
    }

    public function test日本語2文字はバイト数では6でも弾かれる(): void
    {
        // strlen('美味') は 6。バイト数で「5以上」と判定していると通ってしまう。
        $body = json_encode(['review' => ['reviewText' => '美味', 'rating' => 5]], JSON_THROW_ON_ERROR);
        $response = self::handler()->handle(self::request($body), self::USER_ID);

        self::assertSame(400, $response->status());
        self::assertSame('入力内容を確認してください', $response->body()['error']);
    }

    public function test4文字は短すぎるので弾かれる(): void
    {
        $body = json_encode(['review' => ['reviewText' => 'abcd', 'rating' => 3]], JSON_THROW_ON_ERROR);
        self::assertSame(400, self::handler()->handle(self::request($body), self::USER_ID)->status());
    }

    public function test2000文字は通り2001文字は弾かれる(): void
    {
        $ok = json_encode(['review' => ['reviewText' => str_repeat('あ', 2000), 'rating' => 3]], JSON_THROW_ON_ERROR);
        self::assertSame(200, self::handler()->handle(self::request($ok), self::USER_ID)->status());

        $ng = json_encode(['review' => ['reviewText' => str_repeat('あ', 2001), 'rating' => 3]], JSON_THROW_ON_ERROR);
        self::assertSame(400, self::handler()->handle(self::request($ng), self::USER_ID)->status());
    }

    // ── 星の値 ──────────────────────────────────

    /** @return iterable<string, array{string}> */
    public static function 受け付けない星の値(): iterable
    {
        // 生のJSONで書く。json_encode(5.0) は "5" へ正規化されてしまい、
        // 「浮動小数を弾く」ことを確かめたつもりが整数を渡すだけになる
        // （最初そう書いてテストが通らず気づいた）。
        //
        // 文字列と浮動小数を弾くのは Go版・Rails版に合わせるため。
        // ここを緩めると、同じ入力に対して実装ごとに違う答えが返る。
        yield '文字列の5' => ['"5"'];
        yield '浮動小数の5.0' => ['5.0'];
        yield '0' => ['0'];
        yield '6' => ['6'];
        yield 'null' => ['null'];
        yield '真偽値' => ['true'];
        yield '欠けている' => ['null'];
    }

    #[DataProvider('受け付けない星の値')]
    public function test星が整数の1から5でなければ弾かれる(string $ratingJson): void
    {
        $body = '{"review":{"reviewText":"とても良い","rating":' . $ratingJson . '}}';
        $response = self::handler()->handle(self::request($body), self::USER_ID);

        self::assertSame(400, $response->status());
        self::assertSame('入力内容を確認してください', $response->body()['error']);
    }

    /** @return iterable<string, array{string}> */
    public static function 受け付ける星の値(): iterable
    {
        yield '1' => ['1'];
        yield '3' => ['3'];
        yield '5' => ['5'];
    }

    #[DataProvider('受け付ける星の値')]
    public function test星が整数の1から5なら通る(string $ratingJson): void
    {
        // 弾く側だけを確かめると、全部弾いていても気づけない。
        $body = '{"review":{"reviewText":"とても良い","rating":' . $ratingJson . '}}';

        self::assertSame(200, self::handler()->handle(self::request($body), self::USER_ID)->status());
    }

    // ── プロフィール ─────────────────────────────────

    public function testプロフィールが無ければ設定を促す(): void
    {
        $response = self::handler(new FakeProfileReader(null))
            ->handle(self::request(self::validBody()), self::USER_ID);

        self::assertSame(400, $response->status());
        self::assertSame('先にお店のプロフィールを設定してください', $response->body()['error']);
    }

    public function test店名が空のプロフィールも未設定として扱う(): void
    {
        // 行はあるが中身が入っていない状態。生成しても意味のある返信にならない。
        $response = self::handler(new FakeProfileReader(self::profile(storeName: '')))
            ->handle(self::request(self::validBody()), self::USER_ID);

        self::assertSame(400, $response->status());
        self::assertSame('先にお店のプロフィールを設定してください', $response->body()['error']);
    }

    public function testプロフィール取得が失敗したら500を返す(): void
    {
        $response = self::handler(new FakeProfileReader(null, new \RuntimeException('接続できません')))
            ->handle(self::request(self::validBody()), self::USER_ID);

        self::assertSame(500, $response->status());
        self::assertSame('生成に失敗しました。時間をおいて再度お試しください', $response->body()['error']);
    }

    public function test内部エラーの本文に例外メッセージを含めない(): void
    {
        // 例外には接続文字列が混ざりうる。利用者にそのまま返さない。
        $secret = 'postgres://user:とても秘密@db:5432/app';
        $response = self::handler(new FakeProfileReader(null, new \RuntimeException($secret)))
            ->handle(self::request(self::validBody()), self::USER_ID);

        self::assertStringNotContainsString('postgres://', json_encode($response->body(), JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('とても秘密', json_encode($response->body(), JSON_THROW_ON_ERROR));
    }

    // ── 上限 ───────────────────────────────────

    public function test上限に達したら429とfreeプランの文言を返す(): void
    {
        $response = self::handler(
            new FakeProfileReader(self::profile('free')),
            new FakeUsageCounter(throws: new LimitExceeded('上限'))
        )->handle(self::request(self::validBody()), self::USER_ID);

        self::assertSame(429, $response->status());
        self::assertStringContainsString('5件', $response->body()['error']);
        self::assertStringContainsString('プロプラン', $response->body()['error']);
    }

    public function test有料プランの上限文言はアップグレードを案内しない(): void
    {
        $response = self::handler(
            new FakeProfileReader(self::profile('pro')),
            new FakeUsageCounter(throws: new LimitExceeded('上限'))
        )->handle(self::request(self::validBody()), self::USER_ID);

        self::assertSame(429, $response->status());
        self::assertStringContainsString('300件', $response->body()['error']);
        self::assertStringNotContainsString('プロプラン', $response->body()['error']);
    }

    public function testDB側がプランを決められない場合はプロフィール設定を促す(): void
    {
        $response = self::handler(
            null,
            new FakeUsageCounter(throws: new PlanNotFound('プラン不明'))
        )->handle(self::request(self::validBody()), self::USER_ID);

        self::assertSame(400, $response->status());
        self::assertSame('先にお店のプロフィールを設定してください', $response->body()['error']);
    }

    // ── 生成 ───────────────────────────────────

    public function test生成が失敗したら500を返す(): void
    {
        $response = self::handler(null, null, new FakeGenerator(new \RuntimeException('APIが応答しません')))
            ->handle(self::request(self::validBody()), self::USER_ID);

        self::assertSame(500, $response->status());
    }

    public function test成功したら返信と利用状況を返す(): void
    {
        $usage = new FakeUsageCounter(3);
        $response = self::handler(new FakeProfileReader(self::profile('free')), $usage)
            ->handle(self::request(self::validBody()), self::USER_ID);

        self::assertSame(200, $response->status());

        $body = $response->body();
        self::assertSame(3, $body['usage']['used']);
        self::assertSame(5, $body['usage']['limit']);
        self::assertTrue($body['mock']);
        self::assertCount(1, $body['replies']);
        self::assertSame(['style' => 'polite', 'text' => 'テスト用の返信'], $body['replies'][0]);
    }

    public function test加算は利用者IDと集計月で呼ばれる(): void
    {
        $usage = new FakeUsageCounter(1);
        self::handler(null, $usage)->handle(self::request(self::validBody()), self::USER_ID);

        self::assertCount(1, $usage->calls);
        self::assertSame(self::USER_ID, $usage->calls[0][0]);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}$/', $usage->calls[0][1]);
    }

    public function test検証で弾かれた入力では加算しない(): void
    {
        // 形が違うだけで利用枠を消費してしまうと、
        // 送り手は何もできないまま上限に達する。
        $usage = new FakeUsageCounter(1);
        self::handler(null, $usage)->handle(self::request('{"foo":1}'), self::USER_ID);

        self::assertSame([], $usage->calls);
    }

    private static function validBody(): string
    {
        return json_encode(
            ['review' => ['reviewText' => 'とても良い店', 'rating' => 5]],
            JSON_THROW_ON_ERROR
        );
    }
}
