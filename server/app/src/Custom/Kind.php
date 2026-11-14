<?php

declare(strict_types=1);

namespace Linkrot\Custom;

use Linkrot\Web\HeaderTaglines;
use Linkrot\Web\Page;

/** The files a host can replace: the name in the API, the file in app_data/<host>/custom/ and the limits of each. */
enum Kind: string
{
    case Taglines = 'taglines';
    case Headerlines = 'headerlines';

    public function fileName(): string
    {
        return $this->value . '.txt';
    }

    /** Largest body in bytes. */
    public function maxBytes(): int
    {
        return match ($this) {
            self::Taglines => 64 * 1024,
            self::Headerlines => 16 * 1024,
        };
    }

    public function maxLines(): int
    {
        return match ($this) {
            self::Taglines => 2000,
            self::Headerlines => 500,
        };
    }

    /** Longest line in characters. */
    public function maxLineLength(): int
    {
        return match ($this) {
            self::Taglines => 200,
            self::Headerlines => 120,
        };
    }

    /**
     * The built-in lines, what a download shows as long as nothing was uploaded.
     *
     * @return list<string>
     */
    public function defaults(): array
    {
        return match ($this) {
            self::Taglines => Page::taglines(),
            self::Headerlines => HeaderTaglines::all(),
        };
    }

    public function contentType(): string
    {
        return 'text/plain; charset=utf-8';
    }
}
