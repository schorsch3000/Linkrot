<?php

declare(strict_types=1);

namespace Linkrot;

use InvalidArgumentException;

/**
 * Durations like 1h, 2d, 3w, 4m, 5y. The smallest unit is one hour; m is month.
 * For calculation d = 24h, w = 7d, m = 30d, y = 365d.
 */
final class Duration
{
    private const array UNITS = [
        'h' => 3600,
        'd' => 86400,
        'w' => 7 * 86400,
        'm' => 30 * 86400,
        'y' => 365 * 86400,
    ];

    /** @throws InvalidArgumentException */
    public static function parse(string $value): int
    {
        if (preg_match('~^([1-9][0-9]{0,5})([hdwmy])$~', $value, $m) !== 1) {
            throw new InvalidArgumentException("invalid duration '$value', expected <n><h|d|w|m|y> like 1h, 2d, 3w, 4m, 5y");
        }

        return (int) $m[1] * self::UNITS[$m[2]];
    }

    /**
     * Parses and checks against the configured cap.
     *
     * @param string $max the cap as duration string
     *
     * @throws InvalidArgumentException if invalid or above the cap
     */
    public static function parseWithin(string $value, string $max): int
    {
        $seconds = self::parse($value);
        if ($seconds > self::parse($max)) {
            throw new InvalidArgumentException("duration '$value' is above the maximum of $max");
        }

        return $seconds;
    }
}
