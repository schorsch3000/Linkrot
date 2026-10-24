<?php

declare(strict_types=1);

namespace Linkrot\Api;

use Linkrot\Config;
use Linkrot\DownloadMode;
use Linkrot\FileName;
use Linkrot\Files\FileCatalog;
use Linkrot\Files\FileRecord;
use Linkrot\Files\FileRepository;
use Linkrot\Files\FileStore;
use Linkrot\Http\Request;
use Linkrot\Http\Response;
use Linkrot\Web\Page;

/** The public part: /file/{id}/{name} (page) and /dl/{id}/{name} (download). No authentication. */
final readonly class DownloadController
{
    private const array SECURITY_HEADERS = [
        'X-Content-Type-Options' => 'nosniff',
        'Referrer-Policy' => 'no-referrer',
        'Cache-Control' => 'private, no-store',
    ];

    public function __construct(
        private Config $config,
        private FileRepository $files,
        private FileCatalog $catalog,
        private FileStore $store,
    ) {}

    /** GET /file/{id}[/{name}] */
    public function page(string $id, ?string $name): Response
    {
        $file = $this->downloadable($id);
        if (!$file instanceof \Linkrot\Files\FileRecord) {
            return $this->notFound();
        }
        if ($name !== $file->name) {
            return new Response(302, ['Location' => '/file/' . $file->id . '/' . FileName::urlSegment($file->name)] + self::SECURITY_HEADERS);
        }

        $html = Page::file($file, $this->config->baseUrl);

        return new Response(200, [
            'Content-Type' => 'text/html; charset=utf-8',
            'Content-Security-Policy' => Page::fileContentSecurityPolicy(),
        ] + self::SECURITY_HEADERS, $html);
    }

    /** GET|HEAD /dl/{id}[/{name}]; the name segment is not checked. */
    public function download(Request $request, string $id): Response
    {
        $file = $this->downloadable($id);
        if (!$file instanceof \Linkrot\Files\FileRecord) {
            return $this->notFound();
        }
        // HEAD and ranges that do not start at 0 (resumed downloads) never consume a use.
        if ($request->method === 'GET' && $file->usesLeft !== null && $this->startsAtBeginning($request) && !$this->files->consumeUse($file->id)) {
            return $this->notFound();
        }

        $headers = [
            'Content-Type' => 'application/octet-stream',
            'Content-Disposition' => FileName::contentDisposition($file->name),
        ] + self::SECURITY_HEADERS;

        if ($this->config->downloadMode === DownloadMode::Xaccel) {
            return new Response(200, $headers + ['X-Accel-Redirect' => '/_files/' . $file->id]);
        }

        // Development only: PHP streams the file (no range support).
        $headers['Content-Length'] = (string) $file->size;

        return new Response(200, $headers, streamPath: $request->method === 'GET' ? $this->store->path($file->id) : null);
    }

    private function downloadable(string $id): ?FileRecord
    {
        $file = $this->catalog->findLive($id);

        return $file instanceof \Linkrot\Files\FileRecord && $file->isReady() ? $file : null;
    }

    private function startsAtBeginning(Request $request): bool
    {
        $range = $request->header('range');

        return $range === null || preg_match('~^bytes=0*-~', $range) === 1;
    }

    /** Same answer for unknown, expired, exhausted and incomplete files. */
    private function notFound(): Response
    {
        return new Response(404, [
            'Content-Type' => 'text/html; charset=utf-8',
            'Content-Security-Policy' => Page::staticContentSecurityPolicy(),
        ] + self::SECURITY_HEADERS, Page::notFound());
    }
}
