<?php

declare(strict_types=1);

namespace Linkrot\Api;

use Closure;
use InvalidArgumentException;
use Linkrot\Config;
use Linkrot\Duration;
use Linkrot\FileName;
use Linkrot\Files\FileCatalog;
use Linkrot\Files\FileRecord;
use Linkrot\Files\FileRepository;
use Linkrot\Http\Request;
use Linkrot\Http\Response;

/** List, change and delete files. */
final readonly class FileController
{
    /** @param Closure(): int $clock */
    public function __construct(
        private Config $config,
        private FileRepository $files,
        private FileCatalog $catalog,
        private Closure $clock,
    ) {}

    /** GET /api/v1/files */
    public function list(): Response
    {
        return Response::json([
            'files' => array_map(
                fn(FileRecord $file): array => $file->toArray($this->config->baseUrl),
                $this->files->listLive(($this->clock)()),
            ),
        ]);
    }

    /** PATCH /api/v1/files/{id} */
    public function update(Request $request, string $id): Response
    {
        $file = $this->catalog->findLive($id);
        if (!$file instanceof \Linkrot\Files\FileRecord) {
            return Response::error(404, 'not_found', 'not found');
        }
        $body = $request->jsonBody();
        $known = array_intersect_key($body ?? [], array_flip(['name', 'rm_after', 'uses']));
        if ($body === null || $known === [] || count($known) !== count($body)) {
            return Response::error(400, 'invalid_request', 'body must be a JSON object with name, rm_after and/or uses');
        }

        $name = $file->name;
        $rmAfter = $file->rmAfter;
        $expiresAt = $file->expiresAt;
        $usesLeft = $file->usesLeft;
        $now = ($this->clock)();
        try {
            if (array_key_exists('name', $known)) {
                $name = is_string($known['name']) ? FileName::sanitize($known['name']) : null;
                if ($name === null) {
                    throw new InvalidArgumentException('name is unusable');
                }
            }
            if (array_key_exists('rm_after', $known)) {
                if (!is_string($known['rm_after'])) {
                    throw new InvalidArgumentException('rm_after must be a string like 2d');
                }
                $rmAfter = Duration::parseWithin($known['rm_after'], $this->config->maxRmAfter);
                // Counted from now. For an incomplete upload it is applied when the upload completes.
                $expiresAt = $file->isReady() ? $now + $rmAfter : null;
            }
            if (array_key_exists('uses', $known)) {
                if ($known['uses'] !== null && (!is_int($known['uses']) || $known['uses'] < 1)) {
                    throw new InvalidArgumentException('uses must be an integer >= 1 or null');
                }
                $usesLeft = $known['uses'];
            }
        } catch (InvalidArgumentException $e) {
            return Response::error(400, 'invalid_request', $e->getMessage());
        }

        $this->files->updateMetadata($id, $name, $rmAfter, $expiresAt, $usesLeft, $now);
        $updated = $this->files->find($id);

        return $updated instanceof \Linkrot\Files\FileRecord
            ? Response::json($updated->toArray($this->config->baseUrl))
            : Response::error(404, 'not_found', 'not found');
    }

    /** DELETE /api/v1/files/{id} */
    public function delete(string $id): Response
    {
        if (!$this->catalog->findLive($id) instanceof \Linkrot\Files\FileRecord) {
            return Response::error(404, 'not_found', 'not found');
        }
        $this->catalog->remove($id);

        return new Response(204);
    }
}
