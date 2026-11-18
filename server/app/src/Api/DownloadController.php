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

        $path = $this->store->path($file->id);

        return match ($this->config->downloadMode) {
            DownloadMode::Xaccel => new Response(200, $headers + ['X-Accel-Redirect' => '/_files/' . $this->host . '/files/' . $file->id]),
            DownloadMode::Xsendfile => new Response(200, $headers + ['X-Sendfile' => $path]),
            DownloadMode::Litespeed => $this->litespeed($request, $path, $headers),
            DownloadMode::Php => $this->streamed($request, $path, (int) $file->size, $headers),
        };
    }

    /**
     * PHP sends the file itself, with a single range (resuming an interrupted download needs it). Several ranges and
     * a malformed header get the whole file, as nginx does.
     *
     * @param array<string, string> $headers
     */
    private function streamed(Request $request, string $path, int $size, array $headers): Response
    {
        $headers['Accept-Ranges'] = 'bytes';
        $range = self::range($request->header('range'), $size);
        if ($range === false) {
            return new Response(416, $headers + ['Content-Range' => "bytes */$size", 'Content-Length' => '0']);
        }
        $body = $request->method === 'GET' ? $path : null;
        if ($range === null) {
            return new Response(200, $headers + ['Content-Length' => (string) $size], streamPath: $body);
        }
        [$first, $last] = $range;
        $length = $last - $first + 1;

        return new Response(206, $headers + ['Content-Range' => "bytes $first-$last/$size", 'Content-Length' => (string) $length], streamPath: $body, streamOffset: $first, streamLength: $length);
    }

    /**
     * The first and last byte of a single range, false if it cannot be satisfied (416), null if there is none to honour.
     *
     * @return array{int, int}|false|null
     */
    public static function range(?string $header, int $size): array|false|null
    {
        if ($header === null || preg_match('~^bytes=(\d*)-(\d*)$~iD', trim($header), $m) !== 1 || ($m[1] === '' && $m[2] === '')) {
            return null;
        }
        if ($m[1] === '') {
            // the last n bytes
            $suffix = (int) $m[2];
            if ($suffix === 0 || $size === 0) {
                return false;
            }

            return [max(0, $size - $suffix), $size - 1];
        }
        $first = (int) $m[1];
        $last = $m[2] === '' ? $size - 1 : min((int) $m[2], $size - 1);
        if ($m[2] !== '' && (int) $m[2] < $first) {
            return null;
        }

        return $first >= $size ? false : [$first, $last];
    }

    /**
     * LiteSpeed sends the file of an internal URI, so the file has to be below the document root (the setup checks
     * that it cannot be fetched directly).
     *
     * @param array<string, string> $headers
     */
    private function litespeed(Request $request, string $path, array $headers): Response
    {
        $root = realpath($request->server['DOCUMENT_ROOT'] ?? '');
        $file = realpath($path);
        if ($root === false || $file === false || !str_starts_with($file, rtrim($root, '/') . '/')) {
            error_log('linkrot: download_mode litespeed needs the data below the document root, ' . $path . ' is not');

            return Response::error(500, 'internal', 'server misconfigured');
        }
        $uri = implode('/', array_map(rawurlencode(...), explode('/', substr($file, strlen(rtrim($root, '/'))))));

        return new Response(200, $headers + ['X-LiteSpeed-Location' => $uri]);
    }

    private function downloadable(string $id): ?FileRecord
    {
        $file = $this->catalog->findLive($id);

        return $file instanceof \Linkrot\Files\FileRecord && $file->isReady() ? $file : null;
    }

    /**
     * A single range that starts after byte 0 and nothing else: what a client sends to continue an interrupted download.
     * The unit is case-insensitive, as it is for nginx (`Bytes=3-` is served as partial content there too).
     */
    private function isResume(Request $request): bool
    {
        $range = $request->header('range');

        return $range !== null && preg_match('~^bytes=[1-9][0-9]*-[0-9]*$~iD', $range) === 1;
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
