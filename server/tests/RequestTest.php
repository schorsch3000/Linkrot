<?php

declare(strict_types=1);

namespace Linkrot\Tests;

use Linkrot\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RequestTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $server;

    /** @var array<array-key, mixed> */
    private array $get;

    protected function setUp(): void
    {
        $this->server = $_SERVER;
        $this->get = $_GET;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
        $_GET = $this->get;
    }

    private function withBody(string $body): Request
    {
        $stream = fopen('php://memory', 'w+') ?: self::fail('stream');
        fwrite($stream, $body);
        rewind($stream);

        return new Request('POST', '/', [], [], [], $stream);
    }

    public function testFromGlobalsReadsMethodPathQueryAndHeaders(): void
    {
        $_SERVER = [
            'REQUEST_METHOD' => 'patch',
            'REQUEST_URI' => '/api/v1/files/abc%20def/content?x=1',
            'HTTP_UPLOAD_OFFSET' => '10',
            'HTTP_AUTHORIZATION' => 'Bearer lr_x',
            'CONTENT_TYPE' => 'application/offset+octet-stream',
            'CONTENT_LENGTH' => '42',
            'REMOTE_ADDR' => '192.0.2.7',
            'SOME_ARRAY' => ['not a string'],
        ];
        $_GET = ['x' => '1', 'list' => ['a', 'b']];

        $request = Request::fromGlobals();

        self::assertSame('PATCH', $request->method);
        self::assertSame('/api/v1/files/abc def/content', $request->path, 'percent-encoding is decoded, the query is not part of the path');
        self::assertSame('10', $request->header('Upload-Offset'));
        self::assertSame('Bearer lr_x', $request->header('authorization'));
        self::assertSame('application/offset+octet-stream', $request->header('content-type'));
        self::assertSame('42', $request->header('Content-Length'));
        self::assertSame(['x' => '1'], $request->query, 'only string values');
        self::assertSame('192.0.2.7', $request->server['REMOTE_ADDR']);
        self::assertArrayNotHasKey('SOME_ARRAY', $request->server);
    }

    public function testFromGlobalsDefaults(): void
    {
        $_SERVER = [];
        $_GET = [];

        $request = Request::fromGlobals();

        self::assertSame('GET', $request->method);
        self::assertSame('/', $request->path);
    }

    public function testHeaderLookupIsCaseInsensitiveAndMissingIsNull(): void
    {
        $request = new Request('GET', '/', ['x-thing' => 'v']);

        self::assertSame('v', $request->header('X-Thing'));
        self::assertNull($request->header('x-other'));
    }

    /** @return iterable<string, array{string, ?array<string, mixed>}> */
    public static function bodies(): iterable
    {
        yield 'object' => ['{"a":1,"b":"x"}', ['a' => 1, 'b' => 'x']];
        // known quirk: json_decode(assoc) turns {} into [], which is indistinguishable from an empty list
        yield 'empty object' => ['{}', null];
        yield 'list' => ['[1,2]', null];
        yield 'scalar' => ['"text"', null];
        yield 'number' => ['42', null];
        yield 'null' => ['null', null];
        yield 'broken' => ['{"a":', null];
        yield 'empty' => ['', null];
    }

    /** @param array<string, mixed>|null $expected */
    #[DataProvider('bodies')]
    public function testJsonBody(string $body, ?array $expected): void
    {
        self::assertSame($expected, $this->withBody($body)->jsonBody());
    }

    public function testJsonBodyRefusesAnOversizedBody(): void
    {
        $body = '{"a":"' . str_repeat('x', 100) . '"}';

        self::assertNull($this->withBody($body)->jsonBody(50));
        self::assertNotNull($this->withBody($body)->jsonBody(200));
    }
}
