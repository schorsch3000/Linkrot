<?php

declare(strict_types=1);

namespace Linkrot;

use Closure;
use Linkrot\Api\DownloadController;
use Linkrot\Api\FileController;
use Linkrot\Api\UploadController;
use Linkrot\Auth\ApcuRateLimiter;
use Linkrot\Auth\Authenticator;
use Linkrot\Auth\Guard;
use Linkrot\Auth\RateLimiter;
use Linkrot\Auth\TokenStore;
use Linkrot\Files\FileCatalog;
use Linkrot\Files\FileRepository;
use Linkrot\Files\FileStore;
use Linkrot\Http\Request;
use Linkrot\Http\Response;
use Linkrot\Http\Router;
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
        private ?RateLimiter $limiter = null,
        ?Closure $clock = null,
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
        $download = fn(): DownloadController => new DownloadController($this->config, $repository(), $catalog(), $store);
        $this->router->add('GET', '/file/{id}/{name}', static fn(Request $request, array $params): Response => $download()->page($params['id'], $params['name']));
        $this->router->add('GET', '/file/{id}', static fn(Request $request, array $params): Response => $download()->page($params['id'], null));
        foreach (['GET', 'HEAD'] as $method) {
            $this->router->add($method, '/dl/{id}/{name}', static fn(Request $request, array $params): Response => $download()->download($request, $params['id']));
            $this->router->add($method, '/dl/{id}', static fn(Request $request, array $params): Response => $download()->download($request, $params['id']));
        }
        $this->router->add('PATCH', '/api/v1/files/{id}/content', $this->authed(
            static fn(Request $request, array $params): Response => $upload()->chunk($request, $params['id']),
        ));
    }

    /**
     * @param callable(Request, array<string, string>): Response $handler
     *
     * @return Closure(Request, array<string, string>): Response
     */
    private function authed(callable $handler): Closure
    {
        return function (Request $request, array $params) use ($handler): Response {
            $guard = new Guard(
                new Authenticator(new TokenStore($this->db())),
                $this->limiter ??= new ApcuRateLimiter($this->config->rateLimitAttempts, $this->config->rateLimitWindow),
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
        return $this->pdo ??= Database::open($this->dataDir . '/linkrot.sqlite');
    }

    public function handle(Request $request): Response
    {
        try {
            return $this->router->dispatch($request);
        } catch (Throwable $e) {
            error_log('linkrot: ' . $e);

            return Response::error(500, 'internal', 'internal error');
        }
    }
}
