<?php

declare(strict_types=1);

namespace Linkrot\Api;

use Linkrot\Custom\CustomStore;
use Linkrot\Custom\Kind;
use Linkrot\Http\Response;
use Linkrot\SettingsStore;
use Linkrot\Web\Page;

/** `/`: a small page about linkrot itself. Public, shows nothing about the files or the data of this instance. */
final readonly class HomeController
{
    public function __construct(private SettingsStore $settings, private CustomStore $custom) {}

    public function index(): Response
    {
        $settings = $this->settings->get();
        if (!$settings->showStartpage) {
            return new Response(200, [
                'Content-Type' => 'text/html; charset=utf-8',
                'Content-Security-Policy' => Page::staticContentSecurityPolicy(),
                'X-Content-Type-Options' => 'nosniff',
                'Referrer-Policy' => 'no-referrer',
                'Cache-Control' => 'no-store',
            ], '');
        }

        return new Response(200, [
            'Content-Type' => 'text/html; charset=utf-8',
            'Content-Security-Policy' => Page::staticContentSecurityPolicy(),
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer',
            'Cache-Control' => 'no-store',
        ], Page::home($settings, $this->custom->lines(Kind::Taglines)));
    }
}
