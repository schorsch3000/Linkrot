<?php

declare(strict_types=1);

namespace Linkrot\Tests;

use Linkrot\Auth\TokenStore;
use Linkrot\Custom\CssStore;
use Linkrot\Duration;
use Linkrot\Tests\Support\AppTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * In a PCRE pattern `$` also matches in front of a final line feed, so `^...$` accepts "value\n". The route pattern lets
 * %0A through, so every validator of a single value has to refuse it itself (`\z`, or the D modifier).
 */
final class TrailingNewlineTest extends AppTestCase
{
    public function testTheRouterDoesNotMatchAPathWithATrailingNewline(): void
    {
        self::assertSame(200, $this->request('GET', '/api/v1/health')->status);
        self::assertSame(404, $this->request('GET', "/api/v1/health\n")->status);
    }

    public function testASha256WithATrailingNewlineIsRefused(): void
    {
        $sha = hash('sha256', 'abc');

        self::assertSame(201, $this->call('POST', '/api/v1/files', ['name' => 'a.txt', 'size' => 3, 'sha256' => $sha])[0]);
        [$status, $body] = $this->call('POST', '/api/v1/files', ['name' => 'b.txt', 'size' => 3, 'sha256' => $sha . "\n"]);

        self::assertSame(400, $status);
        self::assertSame('invalid_request', $body['error']['code'] ?? null);
    }

    public function testASha256WithATrailingNewlineIsRefusedWhenStreaming(): void
    {
        $id = $this->call('POST', '/api/v1/files', ['name' => 'a.txt', 'stream' => true])[1]['id'] ?? null;
        self::assertIsString($id);

        $status = $this->call('POST', "/api/v1/files/$id/seal", ['size' => 3, 'sha256' => hash('sha256', 'abc') . "\n"])[0];

        self::assertSame(400, $status);
    }

    public function testATokenNameWithATrailingNewlineIsInvalid(): void
    {
        self::assertTrue(TokenStore::isValidName('ci'));
        self::assertFalse(TokenStore::isValidName("ci\n"));
    }

    public function testAStylesheetNameWithATrailingNewlineIsNotOne(): void
    {
        self::assertTrue(CssStore::isFileName('user.0123456789abcdef.css'));
        self::assertFalse(CssStore::isFileName("user.0123456789abcdef.css\n"));
    }

    public function testADurationWithATrailingNewlineIsRefused(): void
    {
        self::assertSame(3600, Duration::parse('1h'));
        $this->expectException(\InvalidArgumentException::class);

        Duration::parse("1h\n");
    }

    /** @return iterable<string, array{string}> */
    public static function slashes(): iterable
    {
        yield 'file id' => ['/dl/%s'];
        yield 'file page' => ['/file/%s'];
    }

    #[DataProvider('slashes')]
    public function testAFileIdWithATrailingNewlineIsNotFound(string $route): void
    {
        $id = $this->upload('abc');

        self::assertSame(200, $this->request('GET', sprintf($route, $id) . '/a.txt', auth: false)->status);
        self::assertSame(404, $this->request('GET', sprintf($route, $id . "\n") . '/a.txt', auth: false)->status);
    }
}
