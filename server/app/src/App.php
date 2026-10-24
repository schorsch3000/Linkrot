<?php

declare(strict_types=1);

namespace Linkrot;

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

    public function __construct(
        public readonly Config $config,
        public readonly string $dataDir,
    ) {
        $this->router = new Router();
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
