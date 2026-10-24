<?php

declare(strict_types=1);

namespace Linkrot;

/**
 * Public file ids: 16 characters, the first 4 from a lowercase set (easier to type and complete), the rest from a
 * larger mixed-case set. Lookalikes 1 0 l o I O are not used. About 90 bits of entropy.
 */
final class FileId
{
    public const int LENGTH = 16;
    public const int LOWER_LENGTH = 4;

    /** 8 digits + 24 letters. */
    public const string LOWER = '23456789abcdefghijkmnpqrstuvwxyz';

    /** 8 digits + 24 lowercase + 24 uppercase letters. */
    public const string FULL = '23456789abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ';

    public static function generate(): string
    {
        $id = '';
        for ($i = 0; $i < self::LENGTH; ++$i) {
            $alphabet = $i < self::LOWER_LENGTH ? self::LOWER : self::FULL;
            $id .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $id;
    }

    /** Exactly 16 characters of the right alphabets. Callers answer 404 for anything else without touching the DB. */
    public static function isValid(string $id): bool
    {
        return strlen($id) === self::LENGTH
            && strspn($id, self::LOWER, 0, self::LOWER_LENGTH) === self::LOWER_LENGTH
            && strspn($id, self::FULL, self::LOWER_LENGTH) === self::LENGTH - self::LOWER_LENGTH;
    }

    /** A usable prefix for CLI lookups: at least 4 characters, all from the lowercase set for the first four. */
    public static function isValidPrefix(string $prefix): bool
    {
        $length = strlen($prefix);
        if ($length < self::LOWER_LENGTH || $length > self::LENGTH) {
            return false;
        }

        return strspn($prefix, self::LOWER, 0, self::LOWER_LENGTH) === self::LOWER_LENGTH
            && strspn($prefix, self::FULL, self::LOWER_LENGTH) === $length - self::LOWER_LENGTH;
    }
}
