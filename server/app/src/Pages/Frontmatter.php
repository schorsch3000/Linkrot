<?php

declare(strict_types=1);

namespace Linkrot\Pages;

use Linkrot\Custom\InvalidCustomFile;

/**
 * The head of a page: a block between two lines of `---` at the very top, one `key: value` per line. Not YAML: no
 * nesting, no lists, no multi-line values, so there is nothing to misread. Empty lines and lines starting with `#` are
 * ignored. Every key is optional; an unknown or repeated key is an error, so that keys can be added later without
 * silently changing what an old file means.
 *
 *     ---
 *     title: Imprint
 *     link: Imprint
 *     order: 10
 *     published: true
 *     lang: de
 *     ---
 */
final class Frontmatter
{
    public const int MAX_TITLE = 100;
    public const int MAX_LINK = 40;

    /**
     * @return array{array{title: ?string, link: ?string, order: int, published: bool, lang: string}, string} the values (null:
     *         not given) and the Markdown below the head
     *
     * @throws InvalidCustomFile
     */
    public static function parse(string $source): array
    {
        $values = ['title' => null, 'link' => null, 'order' => 100, 'published' => true, 'lang' => 'en'];
        $lines = preg_split('~\r\n|\n|\r~', $source) ?: [];
        if (rtrim($lines[0] ?? '') !== '---') {
            return [$values, $source];
        }

        $seen = [];
        $end = null;
        for ($i = 1, $count = count($lines); $i < $count; ++$i) {
            $line = trim($lines[$i]);
            if ($line === '---') {
                $end = $i;
                break;
            }
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $number = $i + 1;
            if (preg_match('~^([a-z]+)\s*:\s*(.*)$~', $line, $m) !== 1) {
                throw new InvalidCustomFile("frontmatter line $number: expected `key: value`");
            }
            [, $key, $raw] = $m;
            if (!array_key_exists($key, $values)) {
                throw new InvalidCustomFile("frontmatter line $number: unknown key `$key` (known: " . implode(', ', array_keys($values)) . ')');
            }
            if (isset($seen[$key])) {
                throw new InvalidCustomFile("frontmatter line $number: `$key` is given twice");
            }
            $seen[$key] = true;
            $values[$key] = self::value($key, self::unquote($raw), $number);
        }
        if ($end === null) {
            throw new InvalidCustomFile('frontmatter is not closed: the second `---` line is missing');
        }

        /** @var array{title: ?string, link: ?string, order: int, published: bool, lang: string} $values */
        return [$values, implode("\n", array_slice($lines, $end + 1))];
    }

    private static function unquote(string $raw): string
    {
        $raw = trim($raw);
        if (strlen($raw) >= 2 && ($raw[0] === '"' || $raw[0] === "'") && $raw[strlen($raw) - 1] === $raw[0]) {
            return substr($raw, 1, -1);
        }

        return $raw;
    }

    private static function value(string $key, string $value, int $number): string|int|bool
    {
        switch ($key) {
            case 'title':
            case 'link':
                $max = $key === 'title' ? self::MAX_TITLE : self::MAX_LINK;
                if ($value === '' || mb_strlen($value) > $max) {
                    throw new InvalidCustomFile("frontmatter line $number: `$key` must have 1 to $max characters");
                }
                if (preg_match('~[\x00-\x1f\x7f]~', $value) === 1) {
                    throw new InvalidCustomFile("frontmatter line $number: `$key` must not contain control characters");
                }

                return $value;
            case 'order':
                if (preg_match('~^-?\d{1,4}$~', $value) !== 1) {
                    throw new InvalidCustomFile("frontmatter line $number: `order` must be a whole number from -9999 to 9999");
                }

                return (int) $value;
            case 'published':
                return match ($value) {
                    'true' => true,
                    'false' => false,
                    default => throw new InvalidCustomFile("frontmatter line $number: `published` must be true or false"),
                };
            default: // lang
                if (preg_match('~^[a-z]{2,3}(-[A-Za-z0-9]{2,8})?$~', $value) !== 1) {
                    throw new InvalidCustomFile("frontmatter line $number: `lang` must be a language code like en or de-AT");
                }

                return $value;
        }
    }
}
