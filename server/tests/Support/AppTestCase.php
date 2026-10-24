<?php

declare(strict_types=1);

namespace Linkrot\Tests\Support;

use Linkrot\App;
use Linkrot\Auth\InMemoryRateLimiter;
use Linkrot\Auth\TokenStore;
use Linkrot\Config;
use Linkrot\Http\Request;
use Linkrot\Http\Response;
use PHPUnit\Framework\TestCase;

/** Boots the application on a temporary data directory with a controllable clock. */
abstract class AppTestCase extends TestCase
{
    protected string $dir;
    protected App $app;
    protected string $token;
    protected int $now = 1_000_000;

    protected function config(): Config
    {
        return new Config(baseUrl: 'https://lr.example', chunkSize: 10, maxRmAfter: '30d');
    }

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/linkrot-test-' . bin2hex(random_bytes(6));
        mkdir($this->dir);
        $this->app = new App(
            $this->config(),
            $this->dir,
            new InMemoryRateLimiter(10, 900, fn(): int => $this->now),
            fn(): int => $this->now,
        );
        $this->token = new TokenStore($this->app->db())->create('test');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/{,files/}*', GLOB_BRACE) ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        @rmdir($this->dir . '/files');
        @rmdir($this->dir);
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>|null $json
     */
    protected function request(string $method, string $path, array $headers = [], ?array $json = null, string $raw = '', bool $auth = true): Response
    {
        $body = $json === null ? $raw : json_encode($json, JSON_THROW_ON_ERROR);
        $stream = fopen('php://memory', 'w+') ?: self::fail('stream');
        fwrite($stream, $body);
        rewind($stream);
        $headers += $auth ? ['authorization' => 'Bearer ' . $this->token] : [];
        $headers += ['content-length' => (string) strlen($body)];

        return $this->app->handle(new Request($method, $path, $headers, [], ['REMOTE_ADDR' => '192.0.2.1'], $stream));
    }

    /**
     * @param array<string, mixed>|null $json
     *
     * @return array{int, array<string, mixed>}
     */
    protected function call(string $method, string $path, ?array $json = null, string $raw = ''): array
    {
        $response = $this->request($method, $path, json: $json, raw: $raw);
        $decoded = json_decode($response->body, true);

        return [$response->status, is_array($decoded) ? $decoded : []];
    }

    /**
     * Uploads a small file in a single request and returns its id.
     *
     * @param array<string, mixed> $extra additional create members (uses, ...)
     */
    protected function upload(string $content, string $name = 'a.txt', string $rmAfter = '1d', array $extra = []): string
    {
        [, $created] = $this->call('POST', '/api/v1/files', ['name' => $name, 'size' => strlen($content), 'sha256' => hash('sha256', $content), 'rm_after' => $rmAfter] + $extra);
        $id = $created['id'];
        self::assertIsString($id);
        $response = $this->request('PATCH', "/api/v1/files/$id/content", [
            'content-type' => 'application/offset+octet-stream',
            'upload-offset' => '0',
        ], raw: $content . hash('sha256', $content, true));
        self::assertSame(200, $response->status, $response->body);

        return $id;
    }
}
