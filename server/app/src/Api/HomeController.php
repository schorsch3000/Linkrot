<?php

declare(strict_types=1);

namespace Linkrot\Api;

use Linkrot\Http\Response;
use Linkrot\Web\Page;

/** `/`: a small page about linkrot itself. Public, shows nothing about the files or the data of this instance. */
final readonly class HomeController
{
    public function index(): Response
    {
        return new Response(200, [
            'Content-Type' => 'text/html; charset=utf-8',
            'Content-Security-Policy' => Page::staticContentSecurityPolicy(),
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer',
            'Cache-Control' => 'public, max-age=300',
        ], Page::home());
    }
}
