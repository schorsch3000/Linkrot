<?php

declare(strict_types=1);

namespace Linkrot\Tests;

use Linkrot\App;
use Linkrot\Auth\InMemoryRateLimiter;
use Linkrot\Auth\TokenStore;
use Linkrot\Config;
use Linkrot\Database;
use Linkrot\Http\Request;
use PHPUnit\Framework\TestCase;

final class UploadTest extends TestCase
{
    private string $dir;
    private App $app;
    private string $token;
    private int $now = 1_000_000;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/linkrot-test-' . bin2hex(random_bytes(6));
        mkdir($this->dir);
        $config = new Config(
            baseUrl: 'https://lr.example',
            chunkSize: 10,
            defaultRmAfter: '1w',
            maxRmAfter: '1y',
        );
        Database::openMigrated($this->dir . '/linkrot.sqlite');
        $this->app = new App($config, $this->dir, new InMemoryRateLimiter(10, 900, fn(): int => $this->now), fn(): int => $this->now);
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
     * @param array<string, string> $headers
     *
     * @return array{int, array<string, mixed>}
     */
    private function call(string $method, string $path, ?array $json = null, array $headers = [], ?string $body = null, bool $auth = true): array
    {
        $body ??= $json === null ? '' : json_encode($json, JSON_THROW_ON_ERROR);
        $stream = fopen('php://memory', 'w+') ?: self::fail('stream');
        fwrite($stream, $body);
        rewind($stream);
        $headers += $auth ? ['authorization' => 'Bearer ' . $this->token] : [];
        $headers += ['content-length' => (string) strlen($body)];
        $response = $this->app->handle(new Request($method, $path, $headers, [], ['REMOTE_ADDR' => '192.0.2.1'], $stream));
        $decoded = json_decode($response->body, true);

        return [$response->status, is_array($decoded) ? $decoded : []];
    }

    /**
     * @param array<string, mixed> $extra
     *
     * @return array{int, array<string, mixed>}
     */
    private function create(string $content, string $name = 'a.txt', array $extra = []): array
    {
        return $this->call('POST', '/api/v1/files', ['name' => $name, 'size' => strlen($content), 'sha256' => hash('sha256', $content)] + $extra);
    }

    /** @return array{int, array<string, mixed>} */
    private function chunk(string $id, int $offset, string $data, ?string $digest = null): array
    {
        return $this->call('PATCH', "/api/v1/files/$id/content", null, [
            'upload-offset' => (string) $offset,
            'content-type' => 'application/offset+octet-stream',
        ], $data . ($digest ?? hash('sha256', $data, true)));
    }

    /** @param array<string, mixed> $response */
    private function id(array $response): string
    {
        self::assertIsString($response['id']);

        return $response['id'];
    }

    public function testRequiresAuth(): void
    {
        self::assertSame(401, $this->call('POST', '/api/v1/files', [], auth: false)[0]);
        self::assertSame(401, $this->call('GET', '/api/v1/files/abcd7KfRtm4pWxnZ', auth: false)[0]);
    }

    public function testFullUpload(): void
    {
        $content = 'hello linkrot, this is 31 bytes';
        [$status, $created] = $this->create($content, 'report.pdf', ['rm_after' => '2d', 'uses' => 3]);

        self::assertSame(201, $status);
        self::assertSame('uploading', $created['status']);
        self::assertSame(10, $created['chunk_size']);
        self::assertSame(0, $created['offset']);
        self::assertTrue(\Linkrot\FileId::isValid($this->id($created)));
        self::assertFileExists($this->dir . '/files/' . $created['id']);
        self::assertSame(strlen($content), filesize($this->dir . '/files/' . $created['id']));
        $id = $this->id($created);

        self::assertSame([200, ['offset' => 10]], $this->chunk($id, 0, substr($content, 0, 10)));
        self::assertSame([200, ['offset' => 20]], $this->chunk($id, 10, substr($content, 10, 10)));
        self::assertSame(20, $this->call('GET', "/api/v1/files/$id")[1]['offset']);
        self::assertSame([200, ['offset' => 30]], $this->chunk($id, 20, substr($content, 20, 10)));

        $this->now += 100;
        [$status, $done] = $this->chunk($id, 30, substr($content, 30));
        self::assertSame(200, $status);
        self::assertSame('ready', $done['status']);
        self::assertSame($this->now + 2 * 86400, $done['expires_at']);
        self::assertSame($this->now, $done['completed_at']);
        self::assertSame(3, $done['uses_left']);
        self::assertSame("https://lr.example/file/$id/report.pdf", $done['url']);
        self::assertSame($content, file_get_contents($this->dir . '/files/' . $id));
    }

    public function testDefaultsApplied(): void
    {
        $content = 'abc';
        $id = $this->id($this->create($content)[1]);
        $done = $this->chunk($id, 0, $content)[1];

        self::assertSame($this->now + 7 * 86400, $done['expires_at']);
        self::assertNull($done['uses_left']);
    }

    public function testResumeReturnsOffsetAndUpdatesMetadata(): void
    {
        $content = str_repeat('x', 25);
        $id = $this->id($this->create($content)[1]);
        $this->chunk($id, 0, substr($content, 0, 10));

        [$status, $resumed] = $this->create($content, 'renamed.txt', ['rm_after' => '3d']);

        self::assertSame(200, $status);
        self::assertSame($id, $resumed['id']);
        self::assertSame(10, $resumed['offset']);
        self::assertSame('renamed.txt', $resumed['name']);
        self::assertSame(10, $resumed['chunk_size']);
    }

    public function testDuplicateOfCompletedFileIsRefused(): void
    {
        $content = 'dup';
        $id = $this->id($this->create($content)[1]);
        $this->chunk($id, 0, $content);

        [$status, $body] = $this->create($content, 'other.txt');

        self::assertSame(409, $status);
        self::assertSame('duplicate', $body['error']['code']);
        self::assertSame($id, $body['error']['id']);
        self::assertSame("https://lr.example/file/$id/a.txt", $body['error']['url']);
    }

    public function testExpiredDuplicateIsReplaced(): void
    {
        $content = 'old';
        $old = $this->id($this->create($content, extra: ['rm_after' => '1h'])[1]);
        $this->chunk($old, 0, $content);
        $this->now += 3601;

        [$status, $new] = $this->create($content);

        self::assertSame(201, $status);
        self::assertNotSame($old, $new['id']);
        self::assertFileDoesNotExist($this->dir . '/files/' . $old);
    }

    public function testOffsetMismatch(): void
    {
        $content = str_repeat('x', 25);
        $id = $this->id($this->create($content)[1]);
        $this->chunk($id, 0, substr($content, 0, 10));

        [$status, $body] = $this->chunk($id, 0, substr($content, 0, 10));

        self::assertSame(409, $status);
        self::assertSame('offset_mismatch', $body['error']['code']);
        self::assertSame(10, $body['error']['offset']);
        self::assertSame(409, $this->chunk($id, 20, 'xxxxx')[0]);
    }

    public function testChunkHashMismatchCommitsNothing(): void
    {
        $content = str_repeat('x', 25);
        $id = $this->id($this->create($content)[1]);

        [$status, $body] = $this->chunk($id, 0, substr($content, 0, 10), str_repeat("\0", 32));

        self::assertSame(422, $status);
        self::assertSame('chunk_hash_mismatch', $body['error']['code']);
        self::assertSame(0, $this->call('GET', "/api/v1/files/$id")[1]['offset']);
        self::assertSame([200, ['offset' => 10]], $this->chunk($id, 0, substr($content, 0, 10)));
    }

    public function testWrongChunkLength(): void
    {
        $content = str_repeat('x', 25);
        $id = $this->id($this->create($content)[1]);

        self::assertSame(400, $this->chunk($id, 0, 'short')[0]);
        self::assertSame(400, $this->chunk($id, 0, str_repeat('x', 11))[0]);
    }

    public function testWholeFileHashMismatchDeletesUpload(): void
    {
        $declared = 'the file i announced';
        $sent = 'the file i sent....!';
        self::assertSame(strlen($declared), strlen($sent));
        $id = $this->id($this->create($declared)[1]);
        $this->chunk($id, 0, substr($sent, 0, 10));

        [$status, $body] = $this->chunk($id, 10, substr($sent, 10));

        self::assertSame(422, $status);
        self::assertSame('file_hash_mismatch', $body['error']['code']);
        self::assertSame(404, $this->call('GET', "/api/v1/files/$id")[0]);
        self::assertFileDoesNotExist($this->dir . '/files/' . $id);
    }

    public function testLockedWhileAnotherRequestWrites(): void
    {
        $content = str_repeat('x', 25);
        $id = $this->id($this->create($content)[1]);
        $other = fopen($this->dir . '/files/' . $id, 'r+b') ?: self::fail('open');
        flock($other, LOCK_EX);

        [$status, $body] = $this->chunk($id, 0, substr($content, 0, 10));
        flock($other, LOCK_UN);

        self::assertSame(409, $status);
        self::assertSame('locked', $body['error']['code']);
        self::assertSame(200, $this->chunk($id, 0, substr($content, 0, 10))[0]);
    }

    public function testEmptyFileIsCompleteAtCreate(): void
    {
        [$status, $body] = $this->create('');

        self::assertSame(201, $status);
        self::assertSame('ready', $body['status']);
        self::assertArrayHasKey('url', $body);
    }

    /** @return iterable<string, array{array<string, mixed>, int}> */
    public static function badCreates(): iterable
    {
        yield 'no name' => [['size' => 1, 'sha256' => str_repeat('a', 64)], 400];
        yield 'bad size' => [['name' => 'a', 'size' => -1, 'sha256' => str_repeat('a', 64)], 400];
        yield 'bad hash' => [['name' => 'a', 'size' => 1, 'sha256' => 'xyz'], 400];
        yield 'empty size wrong hash' => [['name' => 'a', 'size' => 0, 'sha256' => str_repeat('a', 64)], 400];
        yield 'bad duration' => [['name' => 'a', 'size' => 1, 'sha256' => str_repeat('a', 64), 'rm_after' => '5min'], 400];
        yield 'over cap' => [['name' => 'a', 'size' => 1, 'sha256' => str_repeat('a', 64), 'rm_after' => '2y'], 400];
        yield 'bad uses' => [['name' => 'a', 'size' => 1, 'sha256' => str_repeat('a', 64), 'uses' => 0], 400];
        yield 'bad name' => [['name' => '..', 'size' => 1, 'sha256' => str_repeat('a', 64)], 400];
    }

    /** @param array<string, mixed> $json */
    #[\PHPUnit\Framework\Attributes\DataProvider('badCreates')]
    public function testInvalidCreate(array $json, int $expected): void
    {
        self::assertSame($expected, $this->call('POST', '/api/v1/files', $json)[0]);
    }

    public function testUnknownAndMalformedIds(): void
    {
        self::assertSame(404, $this->call('GET', '/api/v1/files/abcd7KfRtm4pWxnZ')[0]);
        self::assertSame(404, $this->call('GET', '/api/v1/files/nope')[0]);
        self::assertSame(404, $this->chunk('abcd7KfRtm4pWxnZ', 0, 'x')[0]);
    }

    public function testFilenameIsSanitisedAndNotUsedOnDisk(): void
    {
        [, $body] = $this->create('x', '../../etc/passwd');

        self::assertSame('passwd', $body['name']);
        self::assertSame([$body['id']], array_map(basename(...), glob($this->dir . '/files/*') ?: []));
    }
}
