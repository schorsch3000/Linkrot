<?php

declare(strict_types=1);

namespace Linkrot\Web;

use Linkrot\Pages\PageMeta;
use Linkrot\Settings;

/** Everything a host changed about the look of its pages and downloads. The defaults are the built-in look. */
final readonly class Appearance
{
    /**
     * @param list<string>|null $taglines footer lines the host uploaded; null: the built-in ones
     * @param list<string>|null $headerLines X-Rot lines the host uploaded; null: the built-in ones
     * @param string|null $cssHref URL of the stylesheet of the host, linked after the built-in one
     * @param list<PageMeta> $pages the published pages, in the order of the footer links
     */
    public function __construct(
        public Settings $settings = new Settings(),
        public ?array $taglines = null,
        public ?array $headerLines = null,
        public ?string $cssHref = null,
        public array $pages = [],
    ) {}
}
