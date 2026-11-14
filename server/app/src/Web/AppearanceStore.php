<?php

declare(strict_types=1);

namespace Linkrot\Web;

use Linkrot\Custom\CssStore;
use Linkrot\Custom\CustomStore;
use Linkrot\Custom\Kind;
use Linkrot\Pages\PageStore;
use Linkrot\SettingsStore;

/** Reads the settings and the custom files of a host into one Appearance. */
final readonly class AppearanceStore
{
    public function __construct(private SettingsStore $settings, private CustomStore $custom, private CssStore $css, private PageStore $pages) {}

    public function get(): Appearance
    {
        return new Appearance(
            $this->settings->get(),
            $this->custom->lines(Kind::Taglines),
            $this->custom->lines(Kind::Headerlines),
            $this->css->href(),
            $this->pages->published(),
        );
    }
}
