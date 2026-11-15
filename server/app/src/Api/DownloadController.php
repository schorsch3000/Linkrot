<?php

declare(strict_types=1);

namespace Linkrot\Api;

use Linkrot\Config;
use Linkrot\DownloadMode;
use Linkrot\Duration;
use Linkrot\FileName;
use Linkrot\Files\FileCatalog;
use Linkrot\Files\FileRecord;
use Linkrot\Files\FileStore;
use Linkrot\Http\Request;
use Linkrot\Http\Response;
use Linkrot\Web\AppearanceStore;
use Linkrot\Web\HeaderTaglines;
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
        private FileCatalog $catalog,
        private FileStore $store,
        private string $host,
        private AppearanceStore $appearance,
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

        $html = Page::file($file, $this->config->baseUrl, $this->appearance->get());

        return new Response(200, [
            'Content-Type' => 'text/html; charset=utf-8',
            'Content-Security-Policy' => Page::scriptContentSecurityPolicy(),
        ] + self::SECURITY_HEADERS, $html);
    }

    /** GET|HEAD /dl/{id}[/{name}]; the name segment is not checked. */
    public function download(Request $request, string $id): Response
    {
        $file = $this->downloadable($id);
        $resume = $this->isResume($request);
        if (!$file instanceof \Linkrot\Files\FileRecord) {
            // The last use is gone, but the download that took it may still be running and be resumed.
            $file = $resume ? $this->catalog->findResumable($id, $this->resumeWindow()) : null;
            if (!$file instanceof \Linkrot\Files\FileRecord) {
                return $this->notFound();
            }
        } elseif ($request->method === 'GET' && $file->usesLeft !== null && (!$resume || !$this->catalog->isWithinResumeWindow($file, $this->resumeWindow())) && !$this->catalog->consumeUse($file->id)) {
            // HEAD and the continuation of a download that was counted a moment ago are free, everything else takes a use.
            return $this->notFound();
        }

        $headers = [
            'Content-Type' => 'application/octet-stream',
            'Content-Disposition' => FileName::contentDisposition($file->name),
        ] + self::SECURITY_HEADERS;
        $look = $this->appearance->get();
        if ($look->settings->showTaglines) {
            $headers[HeaderTaglines::HEADER] = HeaderTaglines::pick($file->id, $look->headerLines);
        }

        if ($this->config->downloadMode === DownloadMode::Xaccel) {
            return new Response(200, $headers + ['X-Accel-Redirect' => '/_files/' . $this->host . '/files/' . $file->id]);
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

    /** A single range that starts after byte 0 and nothing else: what a client sends to continue an interrupted download. */
    private function isResume(Request $request): bool
    {
        $range = $request->header('range');

        return $range !== null && preg_match('~^bytes=[1-9][0-9]*-[0-9]*$~D', $range) === 1;
    }

    private function resumeWindow(): int
    {
        return Duration::parse($this->config->orphanRowTimeout);
    }

    /** Same answer for unknown, expired, exhausted and incomplete files. */
    private function notFound(): Response
    {
        return new Response(404, [
            'Content-Type' => 'text/html; charset=utf-8',
            'Content-Security-Policy' => Page::staticContentSecurityPolicy(),
        ] + self::SECURITY_HEADERS, Page::notFound($this->appearance->get()));
    }
}
