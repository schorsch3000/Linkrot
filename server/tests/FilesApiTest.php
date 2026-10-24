<?php

declare(strict_types=1);

namespace Linkrot\Tests;

use Linkrot\App;
use Linkrot\Auth\InMemoryRateLimiter;
use Linkrot\Auth\TokenStore;
use Linkrot\Config;
use Linkrot\Http\Request;
use PHPUnit\Framework\TestCase;

final class FilesApiTest extends TestCase
{
    private string $dir;
    private App $app;
    private string $token;
    private int $now = 1_000_000;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/linkrot-test-' . bin2hex(random_bytes(6));
        mkdir($this->dir);
        $this->app = new App(
            new Config(baseUrl: 'https://lr.example', chunkSize: 10, maxRmAfter: '30d'),
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
     * @param array<string, mixed>|null $json
     *
     * @return array{int, array<string, mixed>}
     */
    private function call(string $method, string $path, ?array $json = null, string $raw = ''): array
    {
        $body = $json === null ? $raw : json_encode($json, JSON_THROW_ON_ERROR);
        $stream = fopen('php://memory', 'w+') ?: self::fail('stream');
        fwrite($stream, $body);
        rewind($stream);
        $headers = ['authorization' => 'Bearer ' . $this->token, 'content-length' => (string) strlen($body)];
        $response = $this->app->handle(new Request($method, $path, $headers, [], ['REMOTE_ADDR' => '192.0.2.1'], $stream));
        $decoded = json_decode($response->body, true);

        return [$response->status, is_array($decoded) ? $decoded : []];
    }

    /** Creates a finished file (single chunk) and returns its id. */
    private function upload(string $content, string $name = 'a.txt', string $rmAfter = '1d'): string
    {
        [, $created] = $this->call('POST', '/api/v1/files', ['name' => $name, 'size' => strlen($content), 'sha256' => hash('sha256', $content), 'rm_after' => $rmAfter]);
        $id = $created['id'];
        self::assertIsString($id);
        $stream = fopen('php://memory', 'w+') ?: self::fail('stream');
        fwrite($stream, $content . hash('sha256', $content, true));
        rewind($stream);
        $response = $this->app->handle(new Request('PATCH', "/api/v1/files/$id/content", [
            'authorization' => 'Bearer ' . $this->token,
            'content-type' => 'application/offset+octet-stream',
            'upload-offset' => '0',
            'content-length' => (string) (strlen($content) + 32),
        ], [], ['REMOTE_ADDR' => '192.0.2.1'], $stream));
        self::assertSame(200, $response->status, $response->body);

        return $id;
    }

    public function testListNewestFirstIncludingUploads(): void
    {
        $first = $this->upload('one');
        $this->now += 10;
        $second = $this->upload('two');
        $this->now += 10;
        $this->call('POST', '/api/v1/files', ['name' => 'big', 'size' => 99, 'sha256' => str_repeat('b', 64)]);

        [$status, $body] = $this->call('GET', '/api/v1/files');

        self::assertSame(200, $status);
        self::assertIsArray($body['files']);
        self::assertSame(['uploading', 'ready', 'ready'], array_column($body['files'], 'status'));
        self::assertSame([$second, $first], array_slice(array_column($body['files'], 'id'), 1));
    }

    public function testListHidesExpiredAndExhausted(): void
    {
        $this->upload('one', rmAfter: '1h');
        $keep = $this->upload('two', rmAfter: '2d');
        $this->now += 3601;

        [, $body] = $this->call('GET', '/api/v1/files');

        self::assertIsArray($body['files']);
        self::assertSame([$keep], array_column($body['files'], 'id'));
    }

    public function testPatchRmAfterCountsFromNow(): void
    {
        $id = $this->upload('one', rmAfter: '1d');
        $this->now += 3600;

        [$status, $body] = $this->call('PATCH', "/api/v1/files/$id", ['rm_after' => '3d']);

        self::assertSame(200, $status);
        self::assertSame($this->now + 3 * 86400, $body['expires_at']);
    }

    public function testPatchNameAndUses(): void
    {
        $id = $this->upload('one');

        [, $body] = $this->call('PATCH', "/api/v1/files/$id", ['name' => '../new name.pdf', 'uses' => 2]);

        self::assertSame('new name.pdf', $body['name']);
        self::assertSame(2, $body['uses_left']);
        self::assertSame("https://lr.example/file/$id/new%20name.pdf", $body['url']);

        [, $body] = $this->call('PATCH', "/api/v1/files/$id", ['uses' => null]);
        self::assertNull($body['uses_left']);
    }

    public function testPatchRmAfterOfIncompleteUploadIsAppliedAtCompletion(): void
    {
        [, $created] = $this->call('POST', '/api/v1/files', ['name' => 'a', 'size' => 3, 'sha256' => hash('sha256', 'abc')]);
        $id = $created['id'];
        self::assertIsString($id);

        [$status, $body] = $this->call('PATCH', "/api/v1/files/$id", ['rm_after' => '2d']);

        self::assertSame(200, $status);
        self::assertNull($body['expires_at']);
        self::assertSame('uploading', $body['status']);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function badPatches(): iterable
    {
        yield 'empty' => [[]];
        yield 'unknown member' => [['foo' => 1]];
        yield 'bad duration' => [['rm_after' => '5min']];
        yield 'over cap' => [['rm_after' => '31d']];
        yield 'bad uses' => [['uses' => 0]];
        yield 'bad name' => [['name' => '..']];
        yield 'name type' => [['name' => 5]];
    }

    /** @param array<string, mixed> $json */
    #[\PHPUnit\Framework\Attributes\DataProvider('badPatches')]
    public function testInvalidPatch(array $json): void
    {
        $id = $this->upload('one');

        self::assertSame(400, $this->call('PATCH', "/api/v1/files/$id", $json)[0]);
    }

    public function testPatchUnknownAndGarbageBody(): void
    {
        $id = $this->upload('one');

        self::assertSame(404, $this->call('PATCH', '/api/v1/files/abcd7KfRtm4pWxnZ', ['name' => 'x'])[0]);
        self::assertSame(400, $this->call('PATCH', "/api/v1/files/$id", null, 'not json')[0]);
    }

    public function testDelete(): void
    {
        $id = $this->upload('one');
        self::assertFileExists($this->dir . '/files/' . $id);

        self::assertSame(204, $this->call('DELETE', "/api/v1/files/$id")[0]);
        self::assertFileDoesNotExist($this->dir . '/files/' . $id);
        self::assertSame(404, $this->call('GET', "/api/v1/files/$id")[0]);
        self::assertSame(404, $this->call('DELETE', "/api/v1/files/$id")[0]);
    }

    public function testExpiredFileIsNotFoundAndRemoved(): void
    {
        $id = $this->upload('one', rmAfter: '1h');
        $this->now += 3601;

        self::assertSame(404, $this->call('GET', "/api/v1/files/$id")[0]);
        self::assertFileDoesNotExist($this->dir . '/files/' . $id);
    }
}
