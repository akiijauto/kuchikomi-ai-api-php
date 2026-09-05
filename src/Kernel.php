<?php

declare(strict_types=1);

namespace App;

use App\Auth\AuthException;
use App\Auth\JwtIssuer;
use App\Auth\JwtVerifier;
use App\Handler\DemoTokenHandler;
use App\Handler\GenerateHandler;
use App\Handler\HealthHandler;
use App\Http\Request;
use App\Http\Response;
use App\Reply\ClaudeGenerator;
use App\Reply\Generator;
use App\Reply\MockGenerator;
use App\Store\Database;
use App\Store\ProfileStore;
use App\Store\UsageStore;

/**
 * ルーティング表を組み立て、認証が要る経路には認証を適用し、リクエストを1行ログに残す。
 *
 * Go版 internal/app/server.go の Server.New() 相当。あちらは同じ関数の中で
 * mux.HandleFunc(...) を並べているが、PHP側は同じことを Router への登録として行う。
 *
 * Go版との大きな違いは依存の作り方: main.go は起動時に一度だけ store.Open() し、
 * 出来上がった *store.Store をここへ渡している(プロセスが常駐するGoなら
 * それでよい)。PHP はリクエストごとに index.php から作り直される(常駐しない)ため、
 * ここで毎回 Database::connect() すると、DBに触れる必要が無い /api/health まで
 * 接続を試みることになり、HealthHandler のdocコメントが約束している
 * 「DBの不調とアプリの不調を切り分けられる」が壊れる。そのため接続は
 * pdo() で遅延させ、実際に /api/generate か /api/demo/token が呼ばれたときだけ繋ぐ。
 */
final class Kernel
{
    private readonly Router $router;
    private readonly JwtVerifier $verifier;
    private readonly float $startedAt;

    private ?\PDO $pdo = null;
    private ?Generator $generator = null;

    public function __construct(private readonly Config $config)
    {
        $this->startedAt = microtime(true);
        $this->verifier = new JwtVerifier($config->jwtSecret);
        $this->router = new Router();

        $this->registerRoutes();
    }

    /** リクエストを1件処理する。ルーティング・ログを一括りにするのがKernelの役目。 */
    public function handle(Request $request): Response
    {
        $started = microtime(true);

        try {
            $response = $this->router->dispatch($request);
        } catch (\Throwable $e) {
            // ルートは見つかったが、依存の組み立て(DB接続など)やハンドラの外側で
            // 予期せず落ちたときの最後の受け皿。GenerateHandler/DemoTokenHandler
            // 自身のtry/catchでは、依存を作る前の失敗までは捕まえられないため。
            // 個々のハンドラが使っているのと同じ文言にして、利用者から見た挙動を揃える。
            error_log(sprintf('未捕捉の例外: %s: %s', $e::class, $e->getMessage()));
            $response = Response::error(500, '生成に失敗しました。時間をおいて再度お試しください');
        }

        $this->logRequest($request, $response, $started);

        return $response;
    }

    private function registerRoutes(): void
    {
        // 死活監視はDBにも認証にも依存させない。
        // ここがDBを見に行くと「アプリは生きているがDBが不調」でコンテナごと
        // 落とされ、切り分けができなくなる(HealthHandlerのdocコメント参照)。
        $health = new HealthHandler($this->startedAt);
        $this->router->add('GET', '/api/health', fn (Request $r): Response => $health->handle($r));

        // /api/generate はDB(プロフィール・利用回数)と生成器に依存する。
        // Store/Generatorの実体化をここ(クロージャの中)まで遅らせ、実際に
        // このルートが呼ばれるまでDB接続を発生させない(クラスdocコメント参照)。
        $this->addAuth('POST', '/api/generate', function (Request $r, string $userId): Response {
            $handler = new GenerateHandler($this->profileStore(), $this->usageStore(), $this->generator());

            return $handler->handle($r, $userId);
        });

        // デモ用トークン発行口は DEMO_MODE のときだけ「存在する」。
        // ハンドラの中で判定する形にすると、設定を間違えたときに口が開いたままになる。
        // これは「誰でもログイン済みになれる入口」なので、無いことが保証される側に倒す
        // (Go版 server.go の New() と同じ判断)。
        if ($this->config->demoMode) {
            $issuer = new JwtIssuer($this->config->jwtSecret);
            $this->router->add('POST', '/api/demo/token', function (Request $r) use ($issuer): Response {
                $handler = new DemoTokenHandler($this->profileStore(), $issuer);

                return $handler->handle($r);
            });
        }
    }

    /**
     * 認証が要る経路を登録する。
     *
     * @param callable(Request, string): Response $handler userIdを受け取る側
     */
    private function addAuth(string $method, string $path, callable $handler): void
    {
        $this->router->add($method, $path, function (Request $request) use ($handler): Response {
            try {
                $userId = $this->verifier->verifyBearer($request->header('Authorization'));
            } catch (AuthException) {
                return Response::error(401, 'ログインが必要です');
            }

            return $handler($request, $userId);
        });
    }

    private function pdo(): \PDO
    {
        return $this->pdo ??= Database::connect($this->config->databaseUrl);
    }

    private function profileStore(): ProfileStore
    {
        return new ProfileStore($this->pdo());
    }

    private function usageStore(): UsageStore
    {
        return new UsageStore($this->pdo());
    }

    /**
     * 鍵が無いと動かない、ではなく経路は通るが中身はデモにする。
     * 鍵の無いテスト環境やCIでも認証・上限・応答形式まで通して確かめられる
     * (Go版 newGenerator と同じ判断)。一度選んだ生成器はリクエスト内で使い回す。
     */
    private function generator(): Generator
    {
        if ($this->generator !== null) {
            return $this->generator;
        }

        if ($this->config->anthropicApiKey === null) {
            return $this->generator = new MockGenerator();
        }

        // モデル名が空ならClaudeGeneratorの既定値(DEFAULT_MODEL)に委ね、
        // 既定値の文字列をここで重複して持たない。
        return $this->generator = $this->config->anthropicModel !== ''
            ? new ClaudeGenerator($this->config->anthropicApiKey, $this->config->anthropicModel)
            : new ClaudeGenerator($this->config->anthropicApiKey);
    }

    /**
     * 1リクエスト1行のログを出す。
     *
     * Content-Length を必ず出しているのは2026-09-03の教訓による(Go版server.goの
     * コメント参照)。Rails版で「日本語のときだけ400」が起きた原因はアプリではなく
     * 送信側のシェルがUTF-8以外で送っていたことで、受け取ったバイト数の記録が
     * あったおかげで切り分けられた。
     */
    private function logRequest(Request $request, Response $response, float $started): void
    {
        $entry = [
            'method' => $request->method,
            'path' => $request->path,
            'status' => $response->status(),
            'req_content_length' => $this->contentLength($request),
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
        ];
        error_log(json_encode($entry, JSON_UNESCAPED_UNICODE) ?: '{}');
    }

    /**
     * Content-Length ヘッダを整数で返す。無い・数値でないときは-1
     * (Go版の r.ContentLength が「不明なら-1」を返すのに合わせる)。
     */
    private function contentLength(Request $request): int
    {
        $value = $request->header('Content-Length');

        return ($value !== null && ctype_digit($value)) ? (int) $value : -1;
    }
}
