<?php

declare(strict_types=1);

namespace Linkrot\Api;

use Linkrot\Config;
use Linkrot\Custom\InvalidCustomFile;
use Linkrot\Http\Request;
use Linkrot\Http\Response;
use Linkrot\Pages\PageMeta;
use Linkrot\Pages\PageStore;

/** The static pages of a host: list, download the original, upload (render), delete; and the template to start from. */
final readonly class PagesController
{
    public function __construct(private Config $config, private PageStore $pages) {}

    /** GET /api/v1/pages */
    public function list(): Response
    {
        return Response::json(['pages' => array_map($this->toArray(...), $this->pages->all())]);
    }

    /** GET /api/v1/pages/{slug}: the file as it was uploaded; with `?format=html` the rendered body (also of an unpublished page). */
    public function show(Request $request, string $slug): Response
    {
        $source = $this->pages->source($slug);
        if ($source === null || !$this->pages->meta($slug) instanceof \Linkrot\Pages\PageMeta) {
            return $this->notFound();
        }
        if (($request->query['format'] ?? '') === 'html') {
            return new Response(200, ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'no-store'], (string) $this->pages->html($slug));
        }

        return new Response(200, ['Content-Type' => 'text/markdown; charset=utf-8', 'Cache-Control' => 'no-store'], $source);
    }

    /** PUT /api/v1/pages/{slug} */
    public function put(Request $request, string $slug): Response
    {
        $body = stream_get_contents($request->body(), PageStore::MAX_BYTES + 1);
        try {
            $page = $this->pages->put($slug, $body === false ? '' : $body);
        } catch (InvalidCustomFile $e) {
            return Response::error($e->status, $e->status === 413 ? 'too_large' : 'invalid_request', $e->getMessage());
        }

        return Response::json($this->toArray($page));
    }

    /** DELETE /api/v1/pages/{slug}; a page that is not there is fine */
    public function delete(string $slug): Response
    {
        $this->pages->delete($slug);

        return new Response(204);
    }

    /** GET /api/v1/page-template */
    public function template(): Response
    {
        return new Response(200, ['Content-Type' => 'text/markdown; charset=utf-8', 'Cache-Control' => 'no-store'], PageStore::template());
    }

    /** @return array<string, string|int|bool> */
    private function toArray(PageMeta $page): array
    {
        return [
            'slug' => $page->slug,
            'title' => $page->title,
            'link' => $page->link,
            'order' => $page->order,
            'published' => $page->published,
            'lang' => $page->lang,
            'url' => rtrim($this->config->baseUrl, '/') . '/page/' . $page->slug,
            'updated_at' => $page->updatedAt,
        ];
    }

    private function notFound(): Response
    {
        return Response::error(404, 'not_found', 'no such page');
    }
}
