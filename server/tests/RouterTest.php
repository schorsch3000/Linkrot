<?php

declare(strict_types=1);

namespace Linkrot\Tests;

use Linkrot\Http\Request;
use Linkrot\Http\Response;
use Linkrot\Http\Router;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    private function router(): Router
    {
        $router = new Router();
        $router->add('GET', '/file/{id}/{name}', static fn(Request $r, array $p): Response => Response::json($p));
        $router->add('POST', '/api/v1/files', static fn(): Response => new Response(201));

        return $router;
    }

    public function testMatchesParams(): void
    {
        $response = $this->router()->dispatch(new Request('GET', '/file/abc/report.pdf'));

        self::assertSame(200, $response->status);
        self::assertSame('{"id":"abc","name":"report.pdf"}', $response->body);
    }

    public function testNotFound(): void
    {
        $response = $this->router()->dispatch(new Request('GET', '/nope'));

        self::assertSame(404, $response->status);
        self::assertStringContainsString('"not_found"', $response->body);
    }

    public function testMethodNotAllowed(): void
    {
        self::assertSame(405, $this->router()->dispatch(new Request('GET', '/api/v1/files'))->status);
    }

    public function testParamDoesNotCrossSlash(): void
    {
        self::assertSame(404, $this->router()->dispatch(new Request('GET', '/file/a/b/c'))->status);
    }
}
