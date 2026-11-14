<?php

declare(strict_types=1);

namespace Linkrot\Api;

use Linkrot\Http\Request;
use Linkrot\Http\Response;
use Linkrot\Pages\PageStore;
use Linkrot\Web\AppearanceStore;
use Linkrot\Web\Page;

/** `/page/{slug}`: a published page of the host, public. Unpublished and unknown pages are the same 404. */
final readonly class PageController
{
    public function __construct(private PageStore $pages, private AppearanceStore $appearance) {}

    public function show(Request $request, string $slug): Response
    {
        $look = $this->appearance->get();
        $meta = $this->pages->meta($slug);
        $body = $meta instanceof \Linkrot\Pages\PageMeta && $meta->published ? $this->pages->html($slug) : null;
        if (!$meta instanceof \Linkrot\Pages\PageMeta || $body === null) {
            return new Response(404, $this->headers() + ['Content-Security-Policy' => Page::staticContentSecurityPolicy()], Page::notFound($look));
        }

        $html = Page::staticPage($meta, $body, $look);
        // the page also contains the footer links and the look of the host, so the tag is the hash of what is sent
        $etag = '"' . substr(hash('sha256', $html), 0, 16) . '"';
        $headers = $this->headers() + ['ETag' => $etag, 'Cache-Control' => 'no-cache'];
        if (($request->header('if-none-match') ?? '') === $etag) {
            return new Response(304, $headers);
        }

        return new Response(200, $headers + ['Content-Security-Policy' => Page::pageContentSecurityPolicy()], $html);
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return [
            'Content-Type' => 'text/html; charset=utf-8',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer',
        ];
    }
}
