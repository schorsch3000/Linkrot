<?php

declare(strict_types=1);

namespace Linkrot\Tests;

use Linkrot\Http\Response;
use PHPUnit\Framework\TestCase;

final class ResponseTest extends TestCase
{
    private function sent(Response $response): string
    {
        ob_start();
        $response->send();

        return (string) ob_get_clean();
    }

    public function testJsonResponse(): void
    {
        $response = Response::json(['a' => '/x', 'b' => 'é'], 201);

        self::assertSame(201, $response->status);
        self::assertSame('application/json', $response->headers['Content-Type']);
        self::assertSame('{"a":"/x","b":"é"}', $response->body, 'slashes and unicode are not escaped');
    }

    public function testErrorResponseHasTheDocumentedShapeAndKeepsExtraHeaders(): void
    {
        $response = Response::error(409, 'locked', 'busy', ['offset' => 5], ['Retry-After' => '1']);

        self::assertSame(409, $response->status);
        self::assertSame('1', $response->headers['Retry-After']);
        self::assertSame('application/json', $response->headers['Content-Type']);
        self::assertSame(['error' => ['code' => 'locked', 'message' => 'busy', 'offset' => 5]], json_decode($response->body, true));
    }

    public function testSendWritesTheBodyAndSetsTheStatus(): void
    {
        self::assertSame('hello', $this->sent(new Response(200, ['X-Test' => '1'], 'hello')));
        self::assertSame(200, http_response_code());

        self::assertSame('', $this->sent(new Response(404)));
        self::assertSame(404, http_response_code());
        http_response_code(200);
    }

    public function testSendStreamsAFileInsteadOfTheBody(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'linkrot-response-') ?: self::fail('tempnam');
        file_put_contents($path, 'file bytes');
        try {
            self::assertSame('file bytes', $this->sent(new Response(200, [], 'ignored', $path)));
        } finally {
            unlink($path);
        }
    }
}
