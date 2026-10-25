<?php

declare(strict_types=1);

namespace Linkrot\Tests;

use Linkrot\Files\FileStore;
use PHPUnit\Framework\TestCase;

final class FileStoreTest extends TestCase
{
    private string $dir;
    private int $umask;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/linkrot-store-' . bin2hex(random_bytes(6));
        $this->umask = umask(0o077); // a strict umask, as on some hosting setups
    }

    protected function tearDown(): void
    {
        umask($this->umask);
        foreach (glob($this->dir . '/files/*') ?: [] as $file) {
            unlink($file);
        }
        @rmdir($this->dir . '/files');
        @rmdir($this->dir);
    }

    public function testModesDoNotDependOnTheUmask(): void
    {
        $store = new FileStore($this->dir . '/files');

        $store->create('abcd7KfRtm4pWxnZ', 10);

        self::assertSame(0o711, fileperms($this->dir . '/files') & 0o777);
        self::assertSame(0o644, fileperms($this->dir . '/files/abcd7KfRtm4pWxnZ') & 0o777);
        self::assertSame(10, filesize($this->dir . '/files/abcd7KfRtm4pWxnZ'));
    }

    public function testFreeSpaceCreatesTheDirectoryWithTheSameMode(): void
    {
        new FileStore($this->dir . '/files')->freeSpace();

        self::assertSame(0o711, fileperms($this->dir . '/files') & 0o777);
    }

    public function testSpaceReportsTheDiskWithoutCreatingTheDirectory(): void
    {
        mkdir($this->dir);
        $store = new FileStore($this->dir . '/files');

        $space = $store->space();

        self::assertNotNull($space);
        self::assertGreaterThan(0, $space['total']);
        self::assertLessThanOrEqual($space['total'], $space['free']);
        self::assertDirectoryDoesNotExist($this->dir . '/files', 'asking for the space must not create anything');
        self::assertSame($this->dir . '/files', $store->directory());
    }

    public function testSpaceOfAnExistingDirectoryAndOfNothingAtAll(): void
    {
        mkdir($this->dir . '/files', 0o711, true);

        self::assertNotNull(new FileStore($this->dir . '/files')->space());
        self::assertNull(new FileStore($this->dir . '/nowhere/files')->space());
    }

    public function testCreateRefusesNegativeSizesAndExistingFiles(): void
    {
        $store = new FileStore($this->dir . '/files');
        try {
            $store->create('abcdefghabcdefgh', -1);
            self::fail('a negative size must be refused');
        } catch (\RuntimeException $e) {
            self::assertSame('negative size', $e->getMessage());
        }

        $store->create('abcdefghabcdefgh', 3);
        self::assertTrue($store->exists('abcdefghabcdefgh'));
        $this->expectException(\RuntimeException::class);
        $store->create('abcdefghabcdefgh', 3);
    }

    public function testDeleteRemovesTheFileAndIgnoresMissingOnes(): void
    {
        $store = new FileStore($this->dir . '/files');
        $store->create('abcdefghabcdefgh', 1);

        $store->delete('abcdefghabcdefgh');
        $store->delete('abcdefghabcdefgh');

        self::assertFalse($store->exists('abcdefghabcdefgh'));
    }

    public function testADirectoryThatCannotBeCreatedIsReported(): void
    {
        mkdir($this->dir);
        file_put_contents($this->dir . '/blocker', 'a file where the directory should go');
        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('cannot create');
            new FileStore($this->dir . '/blocker/files')->freeSpace();
        } finally {
            unlink($this->dir . '/blocker');
        }
    }
}
