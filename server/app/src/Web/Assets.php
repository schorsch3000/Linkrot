<?php

declare(strict_types=1);

namespace Linkrot\Web;

/**
 * URLs of the files in htdocs/assets. A release build renames them after their content (page.css becomes
 * page.<hash>.css) and writes the mapping to app/assets.php; the URL then changes with the content, so the files can
 * be cached forever. In development there is no manifest and the plain names are used.
 */
final class Assets
{
    public const string URL_PREFIX = '/assets/';

    /** @param string|null $manifest path of the manifest, default app/assets.php */
    public static function url(string $name, ?string $manifest = null): string
    {
        $manifest ??= __DIR__ . '/../../assets.php';
        $map = is_file($manifest) ? require $manifest : [];

        return self::URL_PREFIX . (is_array($map) && is_string($map[$name] ?? null) ? $map[$name] : $name);
    }
}
