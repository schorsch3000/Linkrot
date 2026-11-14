<?php

declare(strict_types=1);

namespace Linkrot\Tests;

use InvalidArgumentException;
use Linkrot\ByteSize;
use Linkrot\Config;
use Linkrot\Tests\Support\AppTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/** The per host quota: the data of all live files and running uploads together. */
final class QuotaTest extends AppTestCase
{
    protected function config(): Config
    {
        return new Config(baseUrl: 'https://lr.example', chunkSize: 100, maxRmAfter: '30d', quota: 100);
    }

    /** @return iterable<string, array{string, int}> */
    public static function sizes(): iterable
    {
        yield 'bytes' => ['100', 100];
        yield 'b' => ['100b', 100];
        yield 'kb' => ['100kb', 100_000];
        yield 'mb' => ['100mb', 100_000_000];
        yield 'gb' => ['100gb', 100_000_000_000];
        yield 'tb' => ['1tb', 1_000_000_000_000];
        yield 'upper case' => ['5GB', 5_000_000_000];
        yield 'space' => ['5 gb', 5_000_000_000];
        yield 'zero' => ['0', 0];
    }

    #[DataProvider('sizes')]
    public function testByteSize(string $text, int $bytes): void
    {
        self::assertSame($bytes, ByteSize::parse($text));
    }

    /** @return iterable<string, array{string}> */
    public static function badSizes(): iterable
    {
        yield 'empty' => [''];
        yield 'unit only' => ['gb'];
        yield 'negative' => ['-1gb'];
        yield 'fraction' => ['1.5gb'];
        yield 'unknown unit' => ['5pb'];
        yield 'leading zero' => ['05'];
        yield 'too many digits' => ['12345678901'];
    }

    #[DataProvider('badSizes')]
    public function testByteSizeRejects(string $text): void
    {
        $this->expectException(InvalidArgumentException::class);
        ByteSize::parse($text);
    }

    public function testConfig(): void
    {
        self::assertSame(0, Config::fromArray(['base_url' => 'https://x.example'])->quota);
        self::assertSame(100_000_000_000, Config::fromArray(['base_url' => 'https://x.example', 'quota' => '100gb'])->quota);
        self::assertSame(5000, Config::fromArray(['base_url' => 'https://x.example', 'quota' => 5000])->quota);
        foreach (['12 parsecs', -1, 1.5, true] as $bad) {
            try {
                Config::fromArray(['base_url' => 'https://x.example', 'quota' => $bad]);
                self::fail('quota ' . var_export($bad, true) . ' was accepted');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /** @return array{int, array<string, mixed>} */
    private function create(string $content, string $name = 'a.txt'): array
    {
        return $this->call('POST', '/api/v1/files', ['name' => $name, 'size' => strlen($content), 'sha256' => hash('sha256', $content)]);
    }

    public function testCreateOverTheQuotaIsRefused(): void
    {
        [$status, $body] = $this->create(str_repeat('a', 101));

        self::assertSame(507, $status);
        self::assertSame('quota_exceeded', $body['error']['code'] ?? null);
    }

    public function testFilesAddUpAndRemovingMakesRoom(): void
    {
        $first = $this->upload(str_repeat('a', 60));

        self::assertSame(507, $this->create(str_repeat('b', 41))[0], '60 + 41 is over 100');
        self::assertSame(201, $this->create(str_repeat('b', 40))[0], '60 + 40 fits exactly');
        self::assertSame(507, $this->create('c')[0], 'now it is full, including the reservation of the running upload');

        self::assertSame(204, $this->request('DELETE', "/api/v1/files/$first")->status);
        self::assertSame(201, $this->create('c')[0]);
    }

    public function testExpiredFilesDoNotCount(): void
    {
        $this->upload(str_repeat('a', 100), rmAfter: '1h');
        self::assertSame(507, $this->create('b')[0]);

        $this->now += 3601;

        self::assertSame(201, $this->create('b')[0]);
    }

    public function testResumeDoesNotCountTwice(): void
    {
        $content = str_repeat('a', 100);
        self::assertSame(201, $this->create($content)[0]);

        self::assertSame(200, $this->create($content)[0], 'the same upload again is a resume, it takes nothing more');
    }

    public function testStreamIsStoppedAtTheQuota(): void
    {
        [, $created] = $this->call('POST', '/api/v1/files', ['name' => 's.txt', 'stream' => true]);
        $id = $created['id'];
        self::assertIsString($id);
        $send = fn(int $offset, string $data): \Linkrot\Http\Response => $this->request('PATCH', "/api/v1/files/$id/content", [
            'content-type' => 'application/offset+octet-stream',
            'upload-offset' => (string) $offset,
        ], raw: $data . hash('sha256', $data, true));
        self::assertSame(200, $send(0, str_repeat('a', 100))->status);

        $response = $send(100, 'b');

        self::assertSame(507, $response->status);
        self::assertStringContainsString('quota_exceeded', $response->body);
        self::assertFileDoesNotExist($this->dir . '/files/' . $id);
        self::assertSame(201, $this->create(str_repeat('c', 100))[0], 'the stopped upload gave its room back');
    }

    public function testStreamCannotStartWhenFull(): void
    {
        $this->upload(str_repeat('a', 100));

        [$status, $body] = $this->call('POST', '/api/v1/files', ['name' => 's.txt', 'stream' => true]);

        self::assertSame(507, $status);
        self::assertSame('quota_exceeded', $body['error']['code'] ?? null);
    }

    public function testListShowsTheUsage(): void
    {
        $this->upload(str_repeat('a', 30));

        [, $body] = $this->call('GET', '/api/v1/files');

        self::assertSame(['used' => 30, 'limit' => 100], $body['quota']);
    }

    public function testNoQuotaMeansNoLimit(): void
    {
        $app = new \Linkrot\App(new Config(baseUrl: 'https://lr.example', chunkSize: 100), $this->dir, 'lr.example', new \Linkrot\Auth\InMemoryRateLimiter(10, 900, fn(): int => $this->now), fn(): int => $this->now);
        $response = $app->handle(new \Linkrot\Http\Request('GET', '/api/v1/files', ['authorization' => 'Bearer ' . $this->token], [], ['REMOTE_ADDR' => '192.0.2.1'], fopen('php://memory', 'r') ?: self::fail('stream')));

        self::assertNull(json_decode($response->body, true)['quota']['limit']);
    }
}
