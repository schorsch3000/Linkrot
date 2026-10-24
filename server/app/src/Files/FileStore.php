<?php

declare(strict_types=1);

namespace Linkrot\Files;

use RuntimeException;

/** The data files in app_data/files, named by id only. */
final readonly class FileStore
{
    /** Readable by the web server user. The ids are unguessable, the directory cannot be listed. */
    public const int FILE_MODE = 0o644;

    /** Search permission only (no listing): nginx can open a file by name, even if it runs as another user. */
    public const int DIRECTORY_MODE = 0o711;

    public function __construct(private string $directory) {}

    public function path(string $id): string
    {
        return $this->directory . '/' . $id;
    }

    public function exists(string $id): bool
    {
        return is_file($this->path($id));
    }

    /** Creates the file with its final size (sparse, stdlib only). */
    public function create(string $id, int $size): void
    {
        if ($size < 0) {
            throw new RuntimeException('negative size');
        }
        $this->ensureDirectory();
        $handle = @fopen($this->path($id), 'xb');
        if ($handle === false) {
            throw new RuntimeException('cannot create data file for ' . $id);
        }
        try {
            // nginx may run as another user than php-fpm and opens the file itself: the mode must not depend on the umask.
            @chmod($this->path($id), self::FILE_MODE);
            if (!ftruncate($handle, $size)) {
                throw new RuntimeException('cannot size data file for ' . $id);
            }
        } finally {
            fclose($handle);
        }
    }

    public function delete(string $id): void
    {
        @unlink($this->path($id));
    }

    public function freeSpace(): int
    {
        $this->ensureDirectory();
        $free = @disk_free_space($this->directory);

        return $free === false ? PHP_INT_MAX : (int) $free;
    }

    private function ensureDirectory(): void
    {
        if (is_dir($this->directory)) {
            return;
        }
        if (!@mkdir($this->directory, self::DIRECTORY_MODE, true) && !is_dir($this->directory)) {
            throw new RuntimeException('cannot create ' . $this->directory);
        }
        @chmod($this->directory, self::DIRECTORY_MODE); // mkdir() is subject to the umask
    }
}
