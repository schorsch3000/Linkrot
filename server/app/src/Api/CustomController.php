<?php

declare(strict_types=1);

namespace Linkrot\Api;

use Linkrot\Custom\CssStore;
use Linkrot\Custom\CustomStore;
use Linkrot\Custom\InvalidCustomFile;
use Linkrot\Custom\Kind;
use Linkrot\Http\Request;
use Linkrot\Http\Response;

/** Download, replace and reset the files of a host: GET, PUT and DELETE /api/v1/custom/{name}. Bodies are plain text. */
final readonly class CustomController
{
    /** The stylesheet is not a list of lines like the other files, it has its own store. */
    private const string CSS = 'css';

    public function __construct(private CustomStore $store, private CssStore $css) {}

    /** The uploaded file, or the built-in defaults; X-Customized says which. */
    public function show(string $name): Response
    {
        if ($name === self::CSS) {
            return new Response(200, [
                'Content-Type' => 'text/css; charset=utf-8',
                'X-Customized' => $this->css->has() ? 'true' : 'false',
                'Cache-Control' => 'no-store',
            ], $this->css->text());
        }
        $kind = Kind::tryFrom($name);
        if ($kind === null) {
            return $this->unknown($name);
        }

        return new Response(200, [
            'Content-Type' => $kind->contentType(),
            'X-Customized' => $this->store->has($kind) ? 'true' : 'false',
            'Cache-Control' => 'no-store',
        ], $this->store->text($kind));
    }

    public function put(Request $request, string $name): Response
    {
        if ($name === self::CSS) {
            $body = stream_get_contents($request->body(), CssStore::MAX_BYTES + 1);
            try {
                $hash = $this->css->put($body === false ? '' : $body);
            } catch (InvalidCustomFile $e) {
                return Response::error($e->status, $e->status === 413 ? 'payload_too_large' : 'invalid_request', $e->getMessage());
            }

            return Response::json(['name' => self::CSS, 'customized' => true, 'url' => $this->css->href(), 'hash' => $hash]);
        }
        $kind = Kind::tryFrom($name);
        if ($kind === null) {
            return $this->unknown($name);
        }
        $body = stream_get_contents($request->body(), $kind->maxBytes() + 1);
        try {
            $lines = $this->store->put($kind, $body === false ? '' : $body);
        } catch (InvalidCustomFile $e) {
            return Response::error($e->status, $e->status === 413 ? 'payload_too_large' : 'invalid_request', $e->getMessage());
        }

        return Response::json(['name' => $kind->value, 'customized' => true, 'lines' => $lines]);
    }

    /** Back to the defaults. Resetting what was never replaced is fine. */
    public function reset(string $name): Response
    {
        if ($name === self::CSS) {
            $this->css->reset();

            return new Response(204);
        }
        $kind = Kind::tryFrom($name);
        if ($kind === null) {
            return $this->unknown($name);
        }
        $this->store->reset($kind);

        return new Response(204);
    }

    /** GET /assets/user.<hash>.css: the stylesheet of the host, current or retired. The name is the hash, so it never changes. */
    public function stylesheet(string $name): Response
    {
        $css = $this->css->read($name);
        if ($css === null) {
            return Response::error(404, 'not_found', 'not found');
        }

        return new Response(200, [
            'Content-Type' => 'text/css; charset=utf-8',
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ], $css);
    }

    private function unknown(string $name): Response
    {
        return Response::error(404, 'not_found', 'unknown file: ' . $name . ' (known: ' . implode(', ', [...array_column(Kind::cases(), 'value'), self::CSS]) . ')');
    }
}
