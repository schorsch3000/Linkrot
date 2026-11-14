<?php

declare(strict_types=1);

namespace Linkrot;

use InvalidArgumentException;

/**
 * Sizes like 100, 100b, 100kb, 100mb, 100gb, 1tb (case does not matter, a space before the unit is fine).
 * The units are decimal (1kb = 1000 bytes), as the chunk size limits are. A plain number is bytes.
 */
final class ByteSize
{
    private const array UNITS = [
        'b' => 1,
        'kb' => 1_000,
        'mb' => 1_000_000,
        'gb' => 1_000_000_000,
        'tb' => 1_000_000_000_000,
    ];

    /** @throws InvalidArgumentException */
    public static function parse(string $value): int
    {
        if (preg_match('~^(0|[1-9][0-9]{0,9})\s?(b|kb|mb|gb|tb)?$~i', trim($value), $m) !== 1) {
            throw new InvalidArgumentException("invalid size '$value', expected <n>[b|kb|mb|gb|tb] like 100, 100kb, 5gb, 1tb");
        }

        return (int) $m[1] * self::UNITS[strtolower($m[2] ?? 'b')];
    }
}
