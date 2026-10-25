<?php

declare(strict_types=1);

namespace Linkrot\Tests;

use Linkrot\Config;
use Linkrot\Database;
use Linkrot\Tests\Support\AppTestCase;

/** The refusals of the upload protocol that the main upload test does not reach. */
final class UploadEdgeTest extends AppTestCase
{
    protected function config(): Config
    {
        return new Config(baseUrl: 'https://lr.example', chunkSize: 10, maxFileSize: 100, maxRmAfter: '30d');
    }

    /** @return array{string, string} id and content of an upload that is waiting for its first chunk */
    private function pending(string $content = 'twenty bytes of data'): array
    {
        [$status, $created] = $this->call('POST', '/api/v1/files', ['name' => 'a.txt', 'size' => strlen($content), 'sha256' => hash('sha256', $content)]);
        self::assertSame(201, $status);
        self::assertIsString($created['id']);

        return [$created['id'], $content];
    }

    /**
     * @param array<string, string> $headers
     *
     * @return array{int, array<string, mixed>}
     */
    private function patch(string $id, string $body, array $headers = []): array
    {
        $response = $this->request('PATCH', "/api/v1/files/$id/content", $headers + [
            'content-type' => 'application/offset+octet-stream',
            'upload-offset' => '0',
        ], raw: $body);
        $decoded = json_decode($response->body, true);

        return [$response->status, is_array($decoded) ? $decoded : []];
    }

    public function testCreateNeedsAJsonObject(): void
    {
        foreach (['not json', '[1,2]', '"text"'] as $raw) {
            $response = $this->request('POST', '/api/v1/files', raw: $raw);

            self::assertSame(400, $response->status, $raw);
            self::assertStringContainsString('body must be a JSON object', $response->body);
        }
    }

    public function testFilesOverTheConfiguredMaximumAreRefused(): void
    {
        [$status, $body] = $this->call('POST', '/api/v1/files', ['name' => 'big.bin', 'size' => 101, 'sha256' => str_repeat('a', 64)]);

        self::assertSame(413, $status);
        self::assertSame('too_large', $body['error']['code']);
        [$status] = $this->call('POST', '/api/v1/files', ['name' => 'ok.bin', 'size' => 100, 'sha256' => str_repeat('b', 64)]);
        self::assertSame(201, $status, 'exactly the maximum is fine');
    }

    public function testRmAfterMustBeAString(): void
    {
        [$status, $body] = $this->call('POST', '/api/v1/files', ['name' => 'a.txt', 'size' => 1, 'sha256' => str_repeat('c', 64), 'rm_after' => 5]);

        self::assertSame(400, $status);
        self::assertSame('rm_after must be a string', $body['error']['message']);
    }

    public function testAnotherSizeForTheSameHashOfARunningUploadIsRefused(): void
    {
        $content = 'twenty bytes of data';
        $this->pending($content);

        [$status, $body] = $this->call('POST', '/api/v1/files', ['name' => 'a.txt', 'size' => strlen($content) + 1, 'sha256' => hash('sha256', $content)]);

        self::assertSame(400, $status);
        self::assertStringContainsString('a different size was announced', $body['error']['message']);
    }

    public function testChunkNeedsItsContentTypeAndOffsetAndLengthHeaders(): void
    {
        [$id] = $this->pending();

        [$status, $body] = $this->patch($id, 'x', ['content-type' => 'text/plain']);
        self::assertSame(400, $status);
        self::assertStringContainsString('Content-Type must be', $body['error']['message']);

        $response = $this->request('PATCH', "/api/v1/files/$id/content", ['content-type' => 'application/offset+octet-stream'], raw: 'x');
        self::assertSame(400, $response->status);
        self::assertStringContainsString('Upload-Offset and Content-Length', $response->body);

        [$status] = $this->patch($id, 'x', ['upload-offset' => 'abc']);
        self::assertSame(400, $status, 'a non numeric offset');
    }

    public function testChunkOfAFinishedOrMissingDataFileIsNotFound(): void
    {
        $ready = $this->upload('done');
        [$status] = $this->patch($ready, 'x');
        self::assertSame(404, $status, 'the file is ready, nothing to upload');

        [$id] = $this->pending();
        unlink($this->dir . '/files/' . $id);
        [$status] = $this->patch($id, str_repeat('x', 10) . hash('sha256', str_repeat('x', 10), true));
        self::assertSame(404, $status, 'the data file is gone');
        self::assertSame(0, (int) Database::query($this->app->db(), "SELECT count(*) FROM files WHERE id = '$id'")->fetchColumn(), 'the row without data goes too');
    }

    public function testBodyShorterThanAnnouncedIsRefusedAndCommitsNothing(): void
    {
        [$id] = $this->pending();

        [$status, $body] = $this->patch($id, 'short', ['content-length' => '42']);

        self::assertSame(400, $status);
        self::assertStringContainsString('shorter than announced', $body['error']['message']);
        [, $state] = $this->call('GET', "/api/v1/files/$id");
        self::assertSame(0, $state['offset']);
    }

    public function testBodyWithoutTheTrailingDigestIsRefused(): void
    {
        [$id] = $this->pending();

        [$status, $body] = $this->patch($id, str_repeat('x', 10) . 'only five', ['content-length' => '42']);

        self::assertSame(400, $status);
        self::assertStringContainsString('trailing sha256', $body['error']['message']);
        [, $state] = $this->call('GET', "/api/v1/files/$id");
        self::assertSame(0, $state['offset']);
    }
}
