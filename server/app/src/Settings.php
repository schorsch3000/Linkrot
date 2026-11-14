<?php

declare(strict_types=1);

namespace Linkrot;

use InvalidArgumentException;

/**
 * What the users of a host switch on and off through the API (`lr config set`). They are stored per host in its
 * database (SettingsStore), all of them are booleans and all of them default to on.
 */
final readonly class Settings
{
    /** The names of the API, in the order they are shown. */
    public const array KEYS = [
        'show_taglines',
        'show_startpage',
        'show_title',
        'show_linkrot_line',
        'show_curl_line',
        'show_hash_line',
    ];

    public function __construct(
        /** The footer tagline of every page and the X-Rot header of downloads. */
        public bool $showTaglines = true,
        /** The about page at `/`; off: an empty page. */
        public bool $showStartpage = true,
        /** The "linkrot" headline with the logo on every page. */
        public bool $showTitle = true,
        /** The `linkrot down` line of the download page. */
        public bool $showLinkrotLine = true,
        /** The curl line of the download page. */
        public bool $showCurlLine = true,
        /** The SHA-256 line of the download page. */
        public bool $showHashLine = true,
    ) {}

    /** The "From a terminal" section of the download page is only there if at least one of its lines is. */
    public function showTerminalSection(): bool
    {
        return $this->showLinkrotLine || $this->showCurlLine || $this->showHashLine;
    }

    /** @return array<string, bool> */
    public function toArray(): array
    {
        return [
            'show_taglines' => $this->showTaglines,
            'show_startpage' => $this->showStartpage,
            'show_title' => $this->showTitle,
            'show_linkrot_line' => $this->showLinkrotLine,
            'show_curl_line' => $this->showCurlLine,
            'show_hash_line' => $this->showHashLine,
        ];
    }

    /** @param array<string, mixed> $values stored values; unknown keys and non-booleans are ignored, the default stays */
    public static function fromArray(array $values): self
    {
        $get = static fn(string $key): bool => is_bool($values[$key] ?? null) ? $values[$key] : true;

        return new self(
            showTaglines: $get('show_taglines'),
            showStartpage: $get('show_startpage'),
            showTitle: $get('show_title'),
            showLinkrotLine: $get('show_linkrot_line'),
            showCurlLine: $get('show_curl_line'),
            showHashLine: $get('show_hash_line'),
        );
    }

    /**
     * Checks a change coming from outside: only known keys, only booleans.
     *
     * @param array<string, mixed> $changes
     *
     * @throws InvalidArgumentException with a message for the client
     */
    public static function validate(array $changes): void
    {
        if ($changes === []) {
            throw new InvalidArgumentException('body must be a JSON object with at least one of: ' . implode(', ', self::KEYS));
        }
        foreach ($changes as $key => $value) {
            if (!in_array($key, self::KEYS, true)) {
                throw new InvalidArgumentException("unknown setting: $key (known: " . implode(', ', self::KEYS) . ')');
            }
            if (!is_bool($value)) {
                throw new InvalidArgumentException("setting $key must be true or false");
            }
        }
    }
}
