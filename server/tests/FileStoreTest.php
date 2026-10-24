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
}
