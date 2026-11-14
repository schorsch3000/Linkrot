<?php

declare(strict_types=1);

namespace Linkrot;

use InvalidArgumentException;

/**
 * Server configuration. app_data/config.php returns an array that is merged over the defaults below.
 *
 * Durations stay strings ("1w", "8h") here; they are parsed where they are used.
 */
final readonly class Config
{
    public const int MIN_CHUNK_SIZE = 10_000_000;
    public const int MAX_CHUNK_SIZE = 100_000_000;

    public function __construct(
        public string $baseUrl,
        public int $chunkSize = 32 * 1024 * 1024,
        public int $maxFileSize = 0,
        public string $defaultRmAfter = '1w',
        public string $maxRmAfter = '1y',
        public ?int $defaultUses = null,
        public string $cleanupInterval = '8h',
        public string $uploadTimeout = '1d',
        public string $orphanRowTimeout = '1h',
        public int $rateLimitAttempts = 10,
        public int $rateLimitWindow = 900,
        public DownloadMode $downloadMode = DownloadMode::Xaccel,
        public int $quota = 0,
    ) {
        if (!preg_match('~^https?://[^/\s]+(/\S*)?$~', $baseUrl)) {
            throw new InvalidArgumentException('config: base URL must be an https URL');
        }
        if (!$this->isSecureOrLoopback($baseUrl)) {
            throw new InvalidArgumentException('config: base URL must use https (http is only allowed for localhost)');
        }
        if ($chunkSize < 1) {
            throw new InvalidArgumentException('config: chunk_size must be positive');
        }
        if ($quota < 0) {
            throw new InvalidArgumentException('config: quota must not be negative');
        }
        if ($maxFileSize < 0 || $rateLimitAttempts < 1 || $rateLimitWindow < 1) {
            throw new InvalidArgumentException('config: max_file_size, rate_limit_* out of range');
        }
        if ($defaultUses !== null && $defaultUses < 1) {
            throw new InvalidArgumentException('config: default_uses must be null (unlimited) or >= 1');
        }
    }

    /** https, or http to localhost / a loopback address (development). Never http to a network. */
    private function isSecureOrLoopback(string $url): bool
    {
        if (str_starts_with($url, 'https://')) {
            return true;
        }
        $host = strtolower(trim((string) parse_url($url, PHP_URL_HOST), '[]'));
        if ($host === 'localhost' || $host === '::1') {
            return true;
        }

        return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false && str_starts_with($host, '127.');
    }

    /**
     * The public URL of a tenant: https://<host>. Only loopback hosts (development) get plain http and, if the request
     * came in on a port, that port, so links work on http://localhost:8080.
     *
     * @param string $authority Host header of the request (with port) if there is one
     */
    public static function baseUrlFor(string $host, ?string $authority = null): string
    {
        $loopback = $host === 'localhost' || str_starts_with($host, '127.');
        if (!$loopback) {
            return 'https://' . $host;
        }
        $authority = strtolower(trim((string) $authority));

        return 'http://' . (Tenants::normalize($authority) === $host ? $authority : $host);
    }

    /**
     * @param array<array-key, mixed> $values keys are the snake_case names documented in docs/SERVER.md
     * @param string $baseUrl see baseUrlFor()
     */
    public static function fromArray(array $values, string $baseUrl): self
    {
        $known = [
            'chunk_size', 'max_file_size', 'default_rm_after', 'max_rm_after', 'default_uses',
            'cleanup_interval', 'upload_timeout', 'orphan_row_timeout', 'rate_limit_attempts',
            'rate_limit_window', 'download_mode', 'quota',
        ];
        if (array_key_exists('base_url', $values)) {
            throw new InvalidArgumentException('config: base_url is gone, the share links are built from the host in global-config.php: remove it');
        }
        $unknown = array_diff(array_map(strval(...), array_keys($values)), $known);
        if ($unknown !== []) {
            throw new InvalidArgumentException('config: unknown keys: ' . implode(', ', $unknown));
        }
        $chunkSize = $values['chunk_size'] ?? null;
        if (is_int($chunkSize) && ($chunkSize < self::MIN_CHUNK_SIZE || $chunkSize > self::MAX_CHUNK_SIZE)) {
            throw new InvalidArgumentException('config: chunk_size must be within 10-100 MB');
        }

        $defaults = new self($baseUrl);
        $mode = $values['download_mode'] ?? $defaults->downloadMode->value;

        return new self(
            baseUrl: $baseUrl,
            chunkSize: self::int($values, 'chunk_size', $defaults->chunkSize),
            maxFileSize: self::int($values, 'max_file_size', $defaults->maxFileSize),
            defaultRmAfter: self::string($values, 'default_rm_after', $defaults->defaultRmAfter),
            maxRmAfter: self::string($values, 'max_rm_after', $defaults->maxRmAfter),
            defaultUses: self::nullableInt($values, 'default_uses'),
            cleanupInterval: self::string($values, 'cleanup_interval', $defaults->cleanupInterval),
            uploadTimeout: self::string($values, 'upload_timeout', $defaults->uploadTimeout),
            orphanRowTimeout: self::string($values, 'orphan_row_timeout', $defaults->orphanRowTimeout),
            rateLimitAttempts: self::int($values, 'rate_limit_attempts', $defaults->rateLimitAttempts),
            rateLimitWindow: self::int($values, 'rate_limit_window', $defaults->rateLimitWindow),
            downloadMode: is_string($mode)
                ? (DownloadMode::tryFrom($mode) ?? throw new InvalidArgumentException('config: download_mode must be xaccel or php'))
                : throw new InvalidArgumentException('config: download_mode must be xaccel or php'),
            quota: self::quota($values),
        );
    }

    public static function load(string $file, string $baseUrl, bool $strict = false): self
    {
        if (!is_file($file)) {
            throw new InvalidArgumentException("config: file not found: $file");
        }
        $values = PhpFile::load($file, 'config', $strict);
        if (!is_array($values)) {
            throw new InvalidArgumentException("config: $file must return an array");
        }

        return self::fromArray($values, $baseUrl);
    }

    /**
     * The upload quota in bytes: a size string like "100gb" or "1tb", or an integer (bytes). 0 is unlimited.
     *
     * @param array<array-key, mixed> $values
     */
    private static function quota(array $values): int
    {
        $value = $values['quota'] ?? 0;
        if (is_int($value)) {
            return $value;
        }
        if (!is_string($value)) {
            throw new InvalidArgumentException('config: quota must be a size like "100gb" or an integer (bytes)');
        }
        try {
            return ByteSize::parse($value);
        } catch (InvalidArgumentException $e) {
            throw new InvalidArgumentException('config: quota: ' . $e->getMessage(), 0, $e);
        }
    }

    /** @param array<array-key, mixed> $values */
    private static function string(array $values, string $key, string $default): string
    {
        $value = $values[$key] ?? $default;

        return is_string($value) ? $value : throw new InvalidArgumentException("config: $key must be a string");
    }

    /** @param array<array-key, mixed> $values */
    private static function int(array $values, string $key, int $default): int
    {
        $value = $values[$key] ?? $default;

        return is_int($value) ? $value : throw new InvalidArgumentException("config: $key must be an integer");
    }

    /** @param array<array-key, mixed> $values */
    private static function nullableInt(array $values, string $key): ?int
    {
        $value = $values[$key] ?? null;

        return match (true) {
            $value === null => null,
            is_int($value) => $value,
            default => throw new InvalidArgumentException("config: $key must be an integer or null"),
        };
    }
}
