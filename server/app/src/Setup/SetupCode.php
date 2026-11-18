<?php

declare(strict_types=1);

namespace Linkrot\Setup;

/**
 * Who may use the setup: whoever can read a file of the webspace. The code is written into setup-code.php next to
 * setup.php; PHP runs that file instead of showing it, so a visitor of the web site never sees the code, the owner
 * opens it with FTP or the file manager of the hoster. The first visitor gets nothing: knowing the URL is not enough.
 *
 * The code is valid while the file exists and was used in the last hour (its modification time). The session cookie
 * is an HMAC of the code, so deleting the file (or "finish" in the setup) ends every session at once.
 */
final readonly class SetupCode
{
    public const string FILE_NAME = 'setup-code.php';
    public const int IDLE = 3600;
    /** A code written by hand (no write permission for PHP) must be at least this long. */
    public const int MIN_LENGTH = 20;

    /** @param \Closure(): int $clock */
    public function __construct(private string $dir, private \Closure $clock) {}

    public function path(): string
    {
        return $this->dir . '/' . self::FILE_NAME;
    }

    /** The current code, or null if there is none (or it is too old, or too short). */
    public function current(): ?string
    {
        $path = $this->path();
        clearstatcache(true, $path);
        $modified = @filemtime($path);
        if ($modified === false || $modified < ($this->clock)() - self::IDLE) {
            return null;
        }
        $content = @file_get_contents($path);
        if ($content === false || preg_match('~^//\s*CODE:\s*(\S+)\s*$~m', $content, $m) !== 1 || strlen($m[1]) < self::MIN_LENGTH) {
            return null;
        }

        return $m[1];
    }

    /**
     * Writes a new code if there is no valid one. A valid code is never replaced: otherwise anybody could keep the owner
     * from logging in by asking for new codes. Returns false if the folder is not writable for PHP.
     */
    public function create(): bool
    {
        if ($this->current() !== null) {
            return true;
        }
        $code = bin2hex(random_bytes(16));
        $until = gmdate('Y-m-d H:i', ($this->clock)() + self::IDLE);
        $php = <<<PHP
            <?php
            // linkrot setup: copy the code below into the setup page. PHP runs this file, nobody can read it over the web.
            // It is valid until it has not been used for an hour (now: until $until UTC); delete the file to lock the setup.
            // CODE: $code

            PHP;
        $path = $this->path();
        if (@file_put_contents($path . '.tmp', $php) === false || !@rename($path . '.tmp', $path)) {
            @unlink($path . '.tmp');

            return false;
        }
        @chmod($path, 0o600);

        return true;
    }

    public function matches(string $given): bool
    {
        $code = $this->current();

        return $code !== null && hash_equals($code, trim($given));
    }

    /** The value of the session cookie for the current code. */
    public function session(): ?string
    {
        $code = $this->current();

        return $code === null ? null : hash_hmac('sha256', 'linkrot setup session', $code);
    }

    public function validSession(?string $cookie): bool
    {
        $session = $this->session();
        if ($session === null || $cookie === null || !hash_equals($session, $cookie)) {
            return false;
        }
        // in use: the hour starts again
        @touch($this->path(), ($this->clock)());

        return true;
    }

    public function destroy(): void
    {
        @unlink($this->path());
    }
}
