<?php

declare(strict_types=1);

namespace Linkrot;

/** Handling of user supplied file names. They are display names only and never used as paths. */
final class FileName
{
    public const int MAX_BYTES = 255;

    /**
     * Reduces the input to a plain base name: no directories, no control characters, valid UTF-8, limited length.
     *
     * @return string|null null if nothing usable is left
     */
    public static function sanitize(string $name): ?string
    {
        if (!mb_check_encoding($name, 'UTF-8')) {
            return null;
        }
        $name = preg_replace('~[\x00-\x1f\x7f]~', '', $name);
        $name = preg_replace('~^.*[/\\\\]~s', '', (string) $name);
        $name = trim((string) $name);
        $name = mb_strcut($name, 0, self::MAX_BYTES, 'UTF-8');
        $name = rtrim($name);

        return in_array($name, ['', '.', '..'], true) ? null : $name;
    }

    /** Value for the Content-Disposition header: ASCII fallback plus RFC 5987 `filename*`. */
    public static function contentDisposition(string $name): string
    {
        $fallback = preg_replace('~[^\x20-\x7e]|["\\\\%;]~u', '_', $name);

        return sprintf("attachment; filename=\"%s\"; filename*=UTF-8''%s", $fallback, rawurlencode($name));
    }

    /** Path segment for URLs. */
    public static function urlSegment(string $name): string
    {
        return rawurlencode($name);
    }
}
