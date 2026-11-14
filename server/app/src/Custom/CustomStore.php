<?php

declare(strict_types=1);

namespace Linkrot\Custom;

use RuntimeException;

/**
 * What a host replaced, in app_data/<host>/custom/. A file only exists after an upload; without it the built-in
 * defaults are used and nothing is written, so a release that adds lines reaches every host that did not replace them.
 */
final readonly class CustomStore
{
    public function __construct(private string $dir) {}

    public function has(Kind $kind): bool
    {
        return is_file($this->path($kind));
    }

    /**
     * The lines of the uploaded file, or null if the host did not upload one.
     *
     * @return list<string>|null
     */
    public function lines(Kind $kind): ?array
    {
        $content = @file_get_contents($this->path($kind));
        if ($content === false) {
            return null;
        }
        $lines = array_values(array_filter(explode("\n", $content), static fn(string $line): bool => $line !== ''));

        return $lines === [] ? null : $lines;
    }

    /** The text of a download: the uploaded file, or the defaults. */
    public function text(Kind $kind): string
    {
        return LineFile::format($this->lines($kind) ?? $kind->defaults());
    }

    /**
     * Validates and replaces the file (atomically: a reader sees the old or the new one, never half of it).
     *
     * @return int number of lines in use
     *
     * @throws InvalidCustomFile
     */
    public function put(Kind $kind, string $body): int
    {
        $lines = LineFile::parse($kind, $body);
        $this->write($this->path($kind), LineFile::format($lines));

        return count($lines);
    }

    public function reset(Kind $kind): void
    {
        $path = $this->path($kind);
        if (is_file($path) && !@unlink($path)) {
            throw new RuntimeException("cannot remove $path");
        }
    }

    private function path(Kind $kind): string
    {
        return $this->dir . '/' . $kind->fileName();
    }

    private function write(string $path, string $content): void
    {
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0o775, true) && !is_dir($this->dir)) {
            throw new RuntimeException('cannot create ' . $this->dir);
        }
        $tmp = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (file_put_contents($tmp, $content) === false || !@rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException("cannot write $path");
        }
    }
}
