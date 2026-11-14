<?php

declare(strict_types=1);

namespace Linkrot\Tests;

use Linkrot\Database;
use Linkrot\Tests\Support\AppTestCase;

/**
 * The cleanup removes a stale upload while a request for it is on its way. A trigger plays the cleanup: the first UPDATE
 * of an incomplete upload deletes the row instead, which is what the request finds when the cleanup was faster.
 */
final class UploadRaceTest extends AppTestCase
{
    private function cleanupWinsTheNextUpdate(): void
    {
        $this->app->db()->exec("CREATE TRIGGER cleanup_wins BEFORE UPDATE ON files WHEN OLD.status = 'uploading'
            BEGIN DELETE FROM files WHERE id = OLD.id; END");
    }

    public function testAResumeThatFindsTheUploadGoneStartsANewOne(): void
    {
        $sha = hash('sha256', '0123456789');
        [$status, $first] = $this->call('POST', '/api/v1/files', ['name' => 'a.txt', 'size' => 10, 'sha256' => $sha]);
        self::assertSame(201, $status);
        $this->cleanupWinsTheNextUpdate();

        [$status, $second] = $this->call('POST', '/api/v1/files', ['name' => 'a.txt', 'size' => 10, 'sha256' => $sha]);

        self::assertSame(201, $status, 'not a 200 that claims to resume an upload that is gone');
        self::assertNotSame($first['id'], $second['id']);
        self::assertSame(0, $second['offset']);
        self::assertSame(1, (int) Database::query($this->app->db(), 'SELECT count(*) FROM files')->fetchColumn());
        self::assertSame(200, $this->call('GET', '/api/v1/files/' . $second['id'])[0]);
        self::assertSame(404, $this->call('GET', '/api/v1/files/' . $first['id'])[0]);
    }

    public function testAChunkWhoseUploadWasRemovedWhileItWasWrittenIsNotAcknowledged(): void
    {
        $content = '0123456789ABCDEFGHIJ';
        [, $created] = $this->call('POST', '/api/v1/files', ['name' => 'a.txt', 'size' => strlen($content), 'sha256' => hash('sha256', $content)]);
        $chunk = substr($content, 0, 10);
        $this->cleanupWinsTheNextUpdate();

        $response = $this->request('PATCH', "/api/v1/files/{$created['id']}/content", [
            'content-type' => 'application/offset+octet-stream',
            'upload-offset' => '0',
        ], raw: $chunk . hash('sha256', $chunk, true));

        self::assertSame(404, $response->status, 'a 200 with an offset would tell the client that the bytes are safe');
    }

    public function testTheLastChunkOfARemovedUploadIsNotReportedAsReady(): void
    {
        $content = '0123456789';
        [, $created] = $this->call('POST', '/api/v1/files', ['name' => 'a.txt', 'size' => 10, 'sha256' => hash('sha256', $content)]);
        $this->cleanupWinsTheNextUpdate();

        $response = $this->request('PATCH', "/api/v1/files/{$created['id']}/content", [
            'content-type' => 'application/offset+octet-stream',
            'upload-offset' => '0',
        ], raw: $content . hash('sha256', $content, true));

        self::assertSame(404, $response->status);
        self::assertStringNotContainsString('url', $response->body);
    }
}
