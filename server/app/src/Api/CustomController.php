<?php

declare(strict_types=1);

namespace Linkrot\Api;

use Linkrot\Custom\CustomStore;
use Linkrot\Custom\InvalidCustomFile;
use Linkrot\Custom\Kind;
use Linkrot\Http\Request;
use Linkrot\Http\Response;

/** Download, replace and reset the files of a host: GET, PUT and DELETE /api/v1/custom/{name}. Bodies are plain text. */
final readonly class CustomController
{
    public function __construct(private CustomStore $store) {}

    /** The uploaded file, or the built-in defaults; X-Customized says which. */
    public function show(string $name): Response
    {
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
        $kind = Kind::tryFrom($name);
        if ($kind === null) {
            return $this->unknown($name);
        }
        $this->store->reset($kind);

        return new Response(204);
    }

    private function unknown(string $name): Response
    {
        return Response::error(404, 'not_found', 'unknown file: ' . $name . ' (known: ' . implode(', ', array_column(Kind::cases(), 'value')) . ')');
    }
}
