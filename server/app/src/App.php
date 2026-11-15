<?php

declare(strict_types=1);

namespace Linkrot;

use Closure;
use Linkrot\Api\AuthController;
use Linkrot\Api\CustomController;
use Linkrot\Api\DownloadController;
use Linkrot\Api\FileController;
use Linkrot\Api\HealthController;
use Linkrot\Api\HomeController;
use Linkrot\Api\PageController;
use Linkrot\Api\PagesController;
use Linkrot\Api\SettingsController;
use Linkrot\Api\TokensController;
use Linkrot\Api\UploadController;
use Linkrot\Auth\ApcuRateLimiter;
use Linkrot\Auth\Authenticator;
use Linkrot\Auth\BearerVerifier;
use Linkrot\Auth\ChallengeStore;
use Linkrot\Auth\Guard;
use Linkrot\Auth\RateLimiter;
use Linkrot\Auth\SessionStore;
use Linkrot\Auth\TokenStore;
use Linkrot\Custom\CssStore;
use Linkrot\Custom\CustomStore;
use Linkrot\Files\Cleanup;
use Linkrot\Files\CleanupLockException;
use Linkrot\Files\FileCatalog;
use Linkrot\Files\FileRepository;
use Linkrot\Files\FileStore;
use Linkrot\Http\Request;
use Linkrot\Http\Response;
use Linkrot\Http\Router;
use Linkrot\Mail\Mailer;
use Linkrot\Pages\PageRenderer;
use Linkrot\Pages\PageStore;
use Linkrot\Web\AppearanceStore;
use PDO;
use Throwable;

/** Application wiring: configuration, database and routes. */
final class App
{
    private ?PDO $pdo = null;
    private readonly Router $router;
    private readonly Closure $clock;

    /** @param (Closure(): int)|null $clock */
    public function __construct(
        public readonly Config $config,
        public readonly string $dataDir,
        public readonly string $host,
        private ?RateLimiter $limiter = null,
        ?Closure $clock = null,
        private readonly ?Mailer $mailer = null,
        private ?RateLimiter $authLimiter = null,
        private readonly float $authDelay = AuthController::START_DURATION,
    ) {
        $this->clock = $clock ?? time(...);
        $this->router = new Router();
        $this->registerRoutes();
    }

    private function registerRoutes(): void
    {
        $repository = fn(): FileRepository => new FileRepository($this->db());
        $store = new FileStore($this->dataDir . '/files');
        $catalog = fn(): FileCatalog => new FileCatalog($repository(), $store, $this->clock);
        $upload = fn(): UploadController => new UploadController($this->config, $repository(), $store, $catalog(), $this->clock);
        $files = fn(): FileController => new FileController($this->config, $repository(), $catalog(), $this->clock);

        $this->router->add('GET', '/api/v1/health', $this->authed(
            fn(Request $request, array $params): Response => new HealthController($this->config, $this->db(), $store, $this->cleanup(), $this->dataDir, $this->clock)->check(),
        ));
        $this->router->add('POST', '/api/v1/files', $this->authed(
            static fn(Request $request, array $params): Response => $upload()->create($request),
        ));
        $this->router->add('GET', '/api/v1/files', $this->authed(
            static fn(Request $request, array $params): Response => $files()->list(),
        ));
        $this->router->add('GET', '/api/v1/files/{id}', $this->authed(
            static fn(Request $request, array $params): Response => $upload()->show($request, $params['id']),
        ));
        $this->router->add('PATCH', '/api/v1/files/{id}', $this->authed(
            static fn(Request $request, array $params): Response => $files()->update($request, $params['id']),
        ));
        $this->router->add('DELETE', '/api/v1/files/{id}', $this->authed(
            static fn(Request $request, array $params): Response => $files()->delete($params['id']),
        ));
        $settings = fn(): SettingsStore => new SettingsStore($this->db());
        $this->router->add('GET', '/api/v1/settings', $this->authed(
            static fn(Request $request, array $params): Response => new SettingsController($settings())->show(),
        ));
        $this->router->add('PATCH', '/api/v1/settings', $this->authed(
            static fn(Request $request, array $params): Response => new SettingsController($settings())->update($request),
        ));
        $custom = new CustomStore($this->dataDir . '/custom');
        $css = $this->css();
        $pages = new PageStore($this->dataDir . '/pages', new PageRenderer($this->host), $this->clock);
        $appearance = fn(): AppearanceStore => new AppearanceStore($settings(), $custom, $css, $pages);
        $pagesApi = fn(): PagesController => new PagesController($this->config, $pages);
        $this->router->add('GET', '/api/v1/pages', $this->authed(static fn(Request $request, array $params): Response => $pagesApi()->list()));
        $this->router->add('GET', '/api/v1/page-template', $this->authed(static fn(Request $request, array $params): Response => $pagesApi()->template()));
        $this->router->add('GET', '/api/v1/pages/{slug}', $this->authed(static fn(Request $request, array $params): Response => $pagesApi()->show($request, $params['slug'])));
        $this->router->add('PUT', '/api/v1/pages/{slug}', $this->authed(static fn(Request $request, array $params): Response => $pagesApi()->put($request, $params['slug'])));
        $this->router->add('DELETE', '/api/v1/pages/{slug}', $this->authed(static fn(Request $request, array $params): Response => $pagesApi()->delete($params['slug'])));
        foreach (['GET', 'HEAD'] as $method) {
            $this->router->add($method, '/page/{slug}', static fn(Request $request, array $params): Response => new PageController($pages, $appearance())->show($request, $params['slug']));
        }
        $this->router->add('GET', '/api/v1/custom/{name}', $this->authed(
            static fn(Request $request, array $params): Response => new CustomController($custom, $css)->show($params['name']),
        ));
        $this->router->add('PUT', '/api/v1/custom/{name}', $this->authed(
            static fn(Request $request, array $params): Response => new CustomController($custom, $css)->put($request, $params['name']),
        ));
        $this->router->add('DELETE', '/api/v1/custom/{name}', $this->authed(
            static fn(Request $request, array $params): Response => new CustomController($custom, $css)->reset($params['name']),
        ));
        foreach (['GET', 'HEAD'] as $method) {
            $this->router->add($method, '/', static fn(): Response => new HomeController($appearance(), $pages)->index());
        }
        // the stylesheet of the host: in production nginx hands unknown /assets/ names to index.php, php -S does the same
        $this->router->add('GET', '/assets/{name}', static fn(Request $request, array $params): Response => new CustomController($custom, $css)->stylesheet($params['name']));
        $download = fn(): DownloadController => new DownloadController($this->config, $catalog(), $store, $this->host, $appearance());
        $this->router->add('GET', '/file/{id}/{name}', static fn(Request $request, array $params): Response => $download()->page($params['id'], $params['name']));
        $this->router->add('GET', '/file/{id}', static fn(Request $request, array $params): Response => $download()->page($params['id'], null));
        foreach (['GET', 'HEAD'] as $method) {
            $this->router->add($method, '/dl/{id}/{name}', static fn(Request $request, array $params): Response => $download()->download($request, $params['id']));
            $this->router->add($method, '/dl/{id}', static fn(Request $request, array $params): Response => $download()->download($request, $params['id']));
        }
        $this->router->add('POST', '/api/v1/cleanup', $this->authed(function (): Response {
            try {
                $result = $this->cleanup()->runNow();
            } catch (CleanupLockException $e) {
                return Response::error(500, 'cleanup_lock', $e->getMessage());
            }

            return Response::json($result instanceof \Linkrot\Files\CleanupResult
                ? ['ran' => true, 'rows_deleted' => $result->rowsDeleted, 'files_deleted' => $result->filesDeleted]
                : ['ran' => false]);
        }));
        $this->registerSelfService();
        $this->router->add('POST', '/api/v1/files/{id}/seal', $this->authed(
            static fn(Request $request, array $params): Response => $upload()->seal($request, $params['id']),
        ));
        $this->router->add('PATCH', '/api/v1/files/{id}/content', $this->authed(
            static fn(Request $request, array $params): Response => $upload()->chunk($request, $params['id']),
        ));
    }

    /** Tokens by mail: a code to the address of the host, a session for it, and the token routes that need one. */
    private function registerSelfService(): void
    {
        $auth = fn(): AuthController => new AuthController(
            $this->config,
            new ChallengeStore($this->db()),
            new SessionStore($this->db(), $this->clock),
            $this->mailer,
            $this->authLimiter ??= new ApcuRateLimiter($this->config->rateLimitAttempts, $this->config->rateLimitWindow, $this->host . ':auth'),
            $this->host,
            $this->authDelay,
        );
        $tokens = fn(): TokensController => new TokensController(new TokenStore($this->db()), $this->mailer, $this->config->email, $this->host);
        $this->router->add('POST', '/api/v1/auth/start', fn(Request $request): Response => $auth()->start($request, ($this->clock)()));
        $this->router->add('POST', '/api/v1/auth/verify', fn(Request $request): Response => $auth()->verify($request, ($this->clock)()));
        $this->router->add('GET', '/api/v1/tokens', $this->authed(
            static fn(Request $request, array $params): Response => $tokens()->list(),
            fn(): BearerVerifier => new SessionStore($this->db(), $this->clock),
        ));
        $this->router->add('POST', '/api/v1/tokens', $this->authed(
            static fn(Request $request, array $params): Response => $tokens()->create($request),
            fn(): BearerVerifier => new SessionStore($this->db(), $this->clock),
        ));
        $this->router->add('DELETE', '/api/v1/tokens/{name}', $this->authed(
            static fn(Request $request, array $params): Response => $tokens()->delete($request, $params['name']),
            fn(): BearerVerifier => new SessionStore($this->db(), $this->clock),
        ));
    }

    /**
     * @param callable(Request, array<string, string>): Response $handler
     * @param (Closure(): BearerVerifier)|null $verifier which tokens open the route: API tokens unless said otherwise
     *
     * @return Closure(Request, array<string, string>): Response
     */
    private function authed(callable $handler, ?Closure $verifier = null): Closure
    {
        return function (Request $request, array $params) use ($handler, $verifier): Response {
            $guard = new Guard(
                new Authenticator($verifier instanceof \Closure ? $verifier() : new TokenStore($this->db())),
                $this->limiter ??= new ApcuRateLimiter($this->config->rateLimitAttempts, $this->config->rateLimitWindow, $this->host),
            );

            return $guard->check($request) ?? $handler($request, $params);
        };
    }

    public function router(): Router
    {
        return $this->router;
    }

    public function db(): PDO
    {
        return $this->pdo ??= Database::openCurrent($this->dataDir . '/linkrot.sqlite');
    }

    public function cleanup(): Cleanup
    {
        return new Cleanup($this->db(), $this->config, $this->dataDir . '/files', $this->dataDir . '/cleanup.lock', $this->clock, $this->css());
    }

    private function css(): CssStore
    {
        return new CssStore($this->dataDir . '/custom', $this->clock);
    }

    /** Called after the response was sent: runs the lazy cleanup if it is due. Never throws. */
    public function cleanupIfDue(): void
    {
        try {
            $this->cleanup()->runIfDue();
        } catch (Throwable $e) {
            error_log('linkrot: cleanup failed: ' . $e);
        }
    }

    public function handle(Request $request): Response
    {
        try {
            return $this->router->dispatch($request);
        } catch (SchemaOutdatedException $e) {
            error_log('linkrot: ' . $e->getMessage());

            return Response::error(503, 'schema_outdated', $e->getMessage());
        } catch (Throwable $e) {
            error_log('linkrot: ' . $e);
            if ($this->isBusy($e)) {
                // another process holds the database and the busy timeout ran out: a state to wait out, not a bug
                return Response::error(503, 'busy', 'the database is busy, try again in a moment', headers: ['Retry-After' => '2']);
            }

            return Response::error(500, 'internal', 'internal error');
        }
    }

    /** SQLITE_BUSY (5) or SQLITE_LOCKED (6), also in their extended forms, anywhere in the chain of causes. */
    private function isBusy(Throwable $e): bool
    {
        for (; $e instanceof Throwable; $e = $e->getPrevious()) {
            if ($e instanceof \PDOException && in_array(((int) ($e->errorInfo[1] ?? 0)) & 0xFF, [5, 6], true)) {
                return true;
            }
        }

        return false;
    }
}
