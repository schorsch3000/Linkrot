<?php

declare(strict_types=1);

namespace Linkrot\Tests;

use Linkrot\Config;
use Linkrot\Tests\Support\AppTestCase;

/** Streaming uploads (stdin): no size and sha256 at create, chunks as usual, `POST /files/{id}/seal` completes. */
final class UploadStreamTest extends AppTestCase
{
    protected function config(): Config
    {
        return new Config(baseUrl: 'https://lr.example', chunkSize: 10, maxFileSize: 100, maxRmAfter: '30d');
    }

    /** @param array<string, mixed> $extra */
    private function stream(array $extra = []): string
    {
        [$status, $created] = $this->call('POST', '/api/v1/files', ['name' => 'stdin.txt', 'stream' => true] + $extra);
        self::assertSame(201, $status);
        self::assertIsString($created['id']);

        return $created['id'];
    }

    /** @return array{int, array<string, mixed>} */
    private function chunk(string $id, int $offset, string $data): array
    {
        $response = $this->request('PATCH', "/api/v1/files/$id/content", [
            'content-type' => 'application/offset+octet-stream',
            'upload-offset' => (string) $offset,
        ], raw: $data . hash('sha256', $data, true));
        $decoded = json_decode($response->body, true);

        return [$response->status, is_array($decoded) ? $decoded : []];
    }

    /** @return array{int, array<string, mixed>} */
    private function seal(string $id, string $content, ?string $sha256 = null, ?int $size = null): array
    {
        return $this->call('POST', "/api/v1/files/$id/seal", ['size' => $size ?? strlen($content), 'sha256' => $sha256 ?? hash('sha256', $content)]);
    }

    private function dataFile(string $id): string
    {
        return $this->dir . '/files/' . $id;
    }

    public function testCreateNeedsNoSizeAndNoHash(): void
    {
        [$status, $created] = $this->call('POST', '/api/v1/files', ['name' => 'stdin.txt', 'stream' => true, 'rm_after' => '2d', 'uses' => 2]);

        self::assertSame(201, $status);
        self::assertSame('uploading', $created['status']);
        self::assertNull($created['size']);
        self::assertNull($created['sha256']);
        self::assertSame(0, $created['offset']);
        self::assertSame(10, $created['chunk_size']);
        self::assertSame(2, $created['uses_left']);
        self::assertArrayNotHasKey('url', $created);
        self::assertIsString($created['id']);
        self::assertSame(0, filesize($this->dataFile($created['id'])));
    }

    public function testTwoStreamsAtTheSameTimeDoNotCollide(): void
    {
        self::assertNotSame($this->stream(), $this->stream());
    }

    public function testStreamWithSizeOrHashIsRefused(): void
    {
        self::assertSame(400, $this->call('POST', '/api/v1/files', ['name' => 'a', 'stream' => true, 'size' => 3])[0]);
        self::assertSame(400, $this->call('POST', '/api/v1/files', ['name' => 'a', 'stream' => true, 'sha256' => str_repeat('a', 64)])[0]);
        self::assertSame(400, $this->call('POST', '/api/v1/files', ['name' => 'a', 'stream' => true, 'rm_after' => '5min'])[0]);
        self::assertSame(400, $this->call('POST', '/api/v1/files', ['name' => '..', 'stream' => true])[0]);
    }

    public function testFullStreamEndingWithAShortChunk(): void
    {
        $content = 'a stream of 25 bytes long';
        $id = $this->stream(['rm_after' => '2d']);

        self::assertSame([200, ['offset' => 10]], $this->chunk($id, 0, substr($content, 0, 10)));
        self::assertSame([200, ['offset' => 20]], $this->chunk($id, 10, substr($content, 10, 10)));
        self::assertSame([200, ['offset' => 25]], $this->chunk($id, 20, substr($content, 20)));
        $listed = $this->call('GET', "/api/v1/files/$id")[1];
        self::assertSame('uploading', $listed['status']);
        self::assertNull($listed['size']);

        $this->now += 50;
        [$status, $done] = $this->seal($id, $content);

        self::assertSame(200, $status);
        self::assertSame('ready', $done['status']);
        self::assertSame(25, $done['size']);
        self::assertSame(hash('sha256', $content), $done['sha256']);
        self::assertSame($this->now + 2 * 86400, $done['expires_at']);
        self::assertSame("https://lr.example/file/$id/stdin.txt", $done['url']);
        self::assertSame($content, file_get_contents($this->dataFile($id)));
    }

    public function testStreamOfExactlyFullChunks(): void
    {
        $content = '01234567890123456789';
        $id = $this->stream();
        $this->chunk($id, 0, substr($content, 0, 10));
        $this->chunk($id, 10, substr($content, 10));

        self::assertSame('ready', $this->seal($id, $content)[1]['status']);
    }

    public function testEmptyStream(): void
    {
        $id = $this->stream();

        [$status, $done] = $this->seal($id, '');

        self::assertSame(200, $status);
        self::assertSame('ready', $done['status']);
        self::assertSame(0, $done['size']);
        self::assertSame(0, filesize($this->dataFile($id)));
    }

    public function testRetriedChunkAndRetriedSealAreHarmless(): void
    {
        $id = $this->stream();
        $this->chunk($id, 0, 'abcdefghij');
        [$status, $mismatch] = $this->chunk($id, 0, 'abcdefghij');
        self::assertSame(409, $status);
        self::assertSame('offset_mismatch', $this->error($mismatch));
        $this->chunk($id, 10, 'xyz');

        $first = $this->seal($id, 'abcdefghijxyz');
        $second = $this->seal($id, 'abcdefghijxyz');

        self::assertSame(200, $first[0]);
        self::assertSame($first, $second);
        self::assertSame(404, $this->seal($id, 'abcdefghijxyz', hash('sha256', 'other'))[0]);
    }

    public function testSealWithAWrongHashDeletesTheUpload(): void
    {
        $id = $this->stream();
        $this->chunk($id, 0, 'abcdefghij');

        [$status, $body] = $this->seal($id, 'abcdefghij', hash('sha256', 'something else'));

        self::assertSame(422, $status);
        self::assertSame('file_hash_mismatch', $this->error($body));
        self::assertFileDoesNotExist($this->dataFile($id));
        self::assertSame(404, $this->call('GET', "/api/v1/files/$id")[0]);
    }

    public function testSealWithAWrongSizeKeepsTheUploadAndTellsTheOffset(): void
    {
        $id = $this->stream();
        $this->chunk($id, 0, 'abcdefghij');

        [$status, $body] = $this->seal($id, 'abcdefghij', size: 15);

        self::assertSame(409, $status);
        self::assertSame('offset_mismatch', $this->error($body));
        self::assertIsArray($body['error']);
        self::assertSame(10, $body['error']['offset']);
        self::assertSame('uploading', $this->call('GET', "/api/v1/files/$id")[1]['status']);
        self::assertSame(200, $this->seal($id, 'abcdefghij')[0]);
    }

    public function testSealOfBytesThatExistAlreadyReportsTheDuplicate(): void
    {
        $existing = $this->upload('abcdefghij');
        $id = $this->stream();
        $this->chunk($id, 0, 'abcdefghij');

        [$status, $body] = $this->seal($id, 'abcdefghij');

        self::assertSame(409, $status);
        self::assertSame('duplicate', $this->error($body));
        self::assertIsArray($body['error']);
        self::assertSame($existing, $body['error']['id']);
        self::assertSame("https://lr.example/file/$existing/a.txt", $body['error']['url']);
        self::assertFileDoesNotExist($this->dataFile($id));
        self::assertFileExists($this->dataFile($existing));
    }

    public function testSealReplacesAnExpiredFileOfTheSameBytes(): void
    {
        $old = $this->upload('abcdefghij', rmAfter: '1h');
        $this->now += 3601;
        $id = $this->stream();
        $this->chunk($id, 0, 'abcdefghij');

        self::assertSame(200, $this->seal($id, 'abcdefghij')[0]);
        self::assertFileDoesNotExist($this->dataFile($old));
    }

    public function testSealWinsOverAnIncompleteUploadOfTheSameBytes(): void
    {
        $content = 'abcdefghij';
        [, $other] = $this->call('POST', '/api/v1/files', ['name' => 'b', 'size' => 10, 'sha256' => hash('sha256', $content)]);
        self::assertIsString($other['id']);
        $id = $this->stream();
        $this->chunk($id, 0, $content);

        self::assertSame(200, $this->seal($id, $content)[0]);
        self::assertFileDoesNotExist($this->dataFile($other['id']));
    }

    public function testChunksOfAStreamAreFullOnesExceptTheLast(): void
    {
        $id = $this->stream();

        self::assertSame(400, $this->chunk($id, 0, str_repeat('a', 11))[0], 'longer than the chunk size');
        self::assertSame(400, $this->chunk($id, 0, '')[0], 'empty');
        self::assertSame(200, $this->chunk($id, 0, 'abc')[0]);
        self::assertSame(400, $this->chunk($id, 3, 'def')[0], 'nothing may follow a short chunk');
    }

    public function testMaxFileSizeStopsAStream(): void
    {
        $id = $this->stream();
        for ($offset = 0; $offset < 100; $offset += 10) {
            self::assertSame(200, $this->chunk($id, $offset, str_repeat('a', 10))[0]);
        }

        [$status, $body] = $this->chunk($id, 100, 'b');

        self::assertSame(413, $status);
        self::assertSame('too_large', $this->error($body));
        self::assertFileDoesNotExist($this->dataFile($id));
    }

    public function testFailedChunkLeavesNoTrashInTheSealedFile(): void
    {
        $id = $this->stream();
        $this->chunk($id, 0, 'abcdefghij');
        $bad = 'XXXXXXXXXX';
        $response = $this->request('PATCH', "/api/v1/files/$id/content", [
            'content-type' => 'application/offset+octet-stream',
            'upload-offset' => '10',
        ], raw: $bad . hash('sha256', 'not the chunk', true));
        self::assertSame(422, $response->status);
        self::assertSame(20, filesize($this->dataFile($id)), 'the rejected bytes are on disk, but behind the offset');
        $this->chunk($id, 10, 'xyz');

        [$status, $done] = $this->seal($id, 'abcdefghijxyz');

        self::assertSame(200, $status);
        self::assertSame(13, $done['size']);
        self::assertSame('abcdefghijxyz', file_get_contents($this->dataFile($id)));
    }

    public function testSealIsRefusedForUnknownIncompleteAndMalformed(): void
    {
        self::assertSame(404, $this->seal('abcd7KfRtm4pWxnZ', 'a')[0]);

        $id = $this->stream();
        self::assertSame(400, $this->call('POST', "/api/v1/files/$id/seal", ['size' => 'x', 'sha256' => hash('sha256', '')])[0]);
        self::assertSame(400, $this->call('POST', "/api/v1/files/$id/seal", ['size' => -1, 'sha256' => hash('sha256', '')])[0]);
        self::assertSame(400, $this->call('POST', "/api/v1/files/$id/seal", ['size' => 0, 'sha256' => 'abc'])[0]);
        self::assertSame(400, $this->call('POST', "/api/v1/files/$id/seal", raw: 'nonsense')[0]);

        [, $regular] = $this->call('POST', '/api/v1/files', ['name' => 'a', 'size' => 3, 'sha256' => hash('sha256', 'abc')]);
        self::assertIsString($regular['id']);
        self::assertSame(404, $this->seal($regular['id'], 'abc')[0], 'only streaming uploads are sealed');
    }

    public function testSealNeedsAuth(): void
    {
        self::assertSame(401, $this->request('POST', '/api/v1/files/abcd7KfRtm4pWxnZ/seal', json: [], auth: false)->status);
    }

    public function testSealIsLockedWhileAnotherRequestWrites(): void
    {
        $id = $this->stream();
        $this->chunk($id, 0, 'abc');
        $handle = fopen($this->dataFile($id), 'r+b') ?: self::fail('open');
        flock($handle, LOCK_EX);

        $response = $this->request('POST', "/api/v1/files/$id/seal", json: ['size' => 3, 'sha256' => hash('sha256', 'abc')]);
        fclose($handle);

        self::assertSame(409, $response->status);
        self::assertSame('1', $response->headers['Retry-After'] ?? null);
    }

    public function testAbandonedStreamsAreCleanedLikeOtherUploads(): void
    {
        $id = $this->stream();
        $this->chunk($id, 0, 'abc');
        $this->now += 10 * 86400;

        $this->app->cleanup()->runNow();

        self::assertSame(404, $this->call('GET', "/api/v1/files/$id")[0]);
        self::assertFileDoesNotExist($this->dataFile($id));
    }

    /** @param array<string, mixed> $body */
    private function error(array $body): mixed
    {
        self::assertIsArray($body['error']);

        return $body['error']['code'];
    }
}
