<?php

declare(strict_types=1);

namespace Linkrot\Files;

use RuntimeException;

/** The data files in app_data/files, named by id only. */
final readonly class FileStore
{
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
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0o770, true) && !is_dir($this->directory)) {
            throw new RuntimeException('cannot create ' . $this->directory);
        }
        $handle = @fopen($this->path($id), 'xb');
        if ($handle === false) {
            throw new RuntimeException('cannot create data file for ' . $id);
        }
        try {
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
        if (!is_dir($this->directory)) {
            @mkdir($this->directory, 0o770, true);
        }
        $free = @disk_free_space($this->directory);

        return $free === false ? PHP_INT_MAX : (int) $free;
    }
}
