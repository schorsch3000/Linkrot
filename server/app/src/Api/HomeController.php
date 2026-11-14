<?php

declare(strict_types=1);

namespace Linkrot\Api;

use Linkrot\Http\Response;
use Linkrot\Pages\PageStore;
use Linkrot\Web\AppearanceStore;
use Linkrot\Web\Page;

/**
 * `/`: a small page about linkrot itself, or the pages of the host that replace it. Public, shows nothing about the files or
 * the data of this instance.
 */
final readonly class HomeController
{
    public function __construct(private AppearanceStore $appearance, private PageStore $pages) {}

    public function index(): Response
    {
        $look = $this->appearance->get();
        if (!$look->settings->showStartpage) {
            return new Response(200, [
                'Content-Type' => 'text/html; charset=utf-8',
                'Content-Security-Policy' => Page::staticContentSecurityPolicy(),
                'X-Content-Type-Options' => 'nosniff',
                'Referrer-Policy' => 'no-referrer',
                'Cache-Control' => 'no-store',
            ], '');
        }

        $replacing = [];
        foreach ($this->pages->startpage() as $page) {
            $html = $this->pages->html($page->slug);
            if ($html !== null) {
                $replacing[] = [$page, $html];
            }
        }
        // pages of the host take the place of the about page: their images may come from https and data: like on /page/
        $csp = $replacing === [] ? Page::staticContentSecurityPolicy() : Page::pageContentSecurityPolicy();

        return new Response(200, [
            'Content-Type' => 'text/html; charset=utf-8',
            'Content-Security-Policy' => $csp,
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer',
            'Cache-Control' => 'no-store',
        ], $replacing === [] ? Page::home($look) : Page::startpage($replacing, $look));
    }
}
