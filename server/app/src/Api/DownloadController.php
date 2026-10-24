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

        $title = htmlspecialchars($file->name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $href = htmlspecialchars('/dl/' . $file->id . '/' . FileName::urlSegment($file->name), ENT_QUOTES, 'UTF-8');
        $size = htmlspecialchars($this->humanSize($file->size), ENT_QUOTES, 'UTF-8');
        $html = <<<HTML
            <!doctype html>
            <html lang="en">
            <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <meta name="robots" content="noindex, nofollow">
            <title>$title</title>
            <style>
            body { font-family: sans-serif; margin: 0; display: flex; min-height: 100vh; align-items: center; justify-content: center; }
            main { text-align: center; padding: 1rem; }
            h1 { font-size: 1.3rem; word-break: break-all; }
            a { display: inline-block; padding: .6rem 1.4rem; border: 1px solid currentColor; border-radius: .3rem; text-decoration: none; color: inherit; }
            </style>
            </head>
            <body>
            <main>
            <h1>$title</h1>
            <p>$size</p>
            <a href="$href">Download</a>
            </main>
            </body>
            </html>

            HTML;

        return new Response(200, [
            'Content-Type' => 'text/html; charset=utf-8',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'",
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
            'Content-Security-Policy' => "default-src 'none'",
        ] + self::SECURITY_HEADERS, "<!doctype html><title>Not found</title><p>Not found.</p>\n");
    }

    private function humanSize(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' bytes';
        }
        $units = ['KiB', 'MiB', 'GiB', 'TiB'];
        $value = (float) $bytes;
        $unit = '';
        foreach ($units as $unit) {
            $value /= 1024;
            if ($value < 1024) {
                break;
            }
        }

        return sprintf('%.1f %s (%s bytes)', $value, $unit, number_format($bytes, 0, '.', ' '));
    }
}
