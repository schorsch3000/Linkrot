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
    ) {
        if (!preg_match('~^https?://[^/\s]+(/\S*)?$~', $baseUrl)) {
            throw new InvalidArgumentException('config: base_url must be an http(s) URL');
        }
        if ($chunkSize < self::MIN_CHUNK_SIZE || $chunkSize > self::MAX_CHUNK_SIZE) {
            throw new InvalidArgumentException('config: chunk_size must be within 10-100 MB');
        }
        if ($maxFileSize < 0 || $rateLimitAttempts < 1 || $rateLimitWindow < 1) {
            throw new InvalidArgumentException('config: max_file_size, rate_limit_* out of range');
        }
        if ($defaultUses !== null && $defaultUses < 1) {
            throw new InvalidArgumentException('config: default_uses must be null (unlimited) or >= 1');
        }
    }

    /**
     * @param array<array-key, mixed> $values keys are the snake_case names documented in docs/SERVER.md
     */
    public static function fromArray(array $values): self
    {
        $known = [
            'base_url', 'chunk_size', 'max_file_size', 'default_rm_after', 'max_rm_after', 'default_uses',
            'cleanup_interval', 'upload_timeout', 'orphan_row_timeout', 'rate_limit_attempts',
            'rate_limit_window', 'download_mode',
        ];
        $unknown = array_diff(array_map(strval(...), array_keys($values)), $known);
        if ($unknown !== []) {
            throw new InvalidArgumentException('config: unknown keys: ' . implode(', ', $unknown));
        }
        if (!array_key_exists('base_url', $values)) {
            throw new InvalidArgumentException('config: base_url is required');
        }

        $defaults = new self('http://localhost');
        $mode = $values['download_mode'] ?? $defaults->downloadMode->value;

        return new self(
            baseUrl: self::string($values, 'base_url', ''),
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
        );
    }

    public static function load(string $file): self
    {
        if (!is_file($file)) {
            throw new InvalidArgumentException("config: file not found: $file");
        }
        $values = (static fn(): mixed => require $file)();
        if (!is_array($values)) {
            throw new InvalidArgumentException("config: $file must return an array");
        }

        return self::fromArray($values);
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
