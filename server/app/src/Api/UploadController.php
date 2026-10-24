<?php

declare(strict_types=1);

namespace Linkrot\Api;

use Closure;
use InvalidArgumentException;
use Linkrot\Config;
use Linkrot\Duration;
use Linkrot\FileId;
use Linkrot\FileName;
use Linkrot\Files\FileCatalog;
use Linkrot\Files\FileRecord;
use Linkrot\Files\FileRepository;
use Linkrot\Files\FileStatus;
use Linkrot\Files\FileStore;
use Linkrot\Http\Request;
use Linkrot\Http\Response;
use PDOException;

/** The upload protocol of docs/API.md: create/resume, chunks, completion. */
final readonly class UploadController
{
    public const int DIGEST_LENGTH = 32;
    public const string CHUNK_CONTENT_TYPE = 'application/offset+octet-stream';
    private const string EMPTY_SHA256 = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

    /** @param Closure(): int $clock */
    public function __construct(
        private Config $config,
        private FileRepository $files,
        private FileStore $store,
        private FileCatalog $catalog,
        private Closure $clock,
    ) {}

    /** POST /api/v1/files */
    public function create(Request $request): Response
    {
        $body = $request->jsonBody();
        if ($body === null) {
            return $this->invalid('body must be a JSON object');
        }
        $name = is_string($body['name'] ?? null) ? FileName::sanitize($body['name']) : null;
        $size = $body['size'] ?? null;
        $sha256 = is_string($body['sha256'] ?? null) ? strtolower($body['sha256']) : '';
        if ($name === null) {
            return $this->invalid('name is missing or unusable');
        }
        if (!is_int($size) || $size < 0) {
            return $this->invalid('size must be a non-negative integer');
        }
        if (preg_match('~^[0-9a-f]{64}$~', $sha256) !== 1) {
            return $this->invalid('sha256 must be 64 hex characters');
        }
        if ($size === 0 && $sha256 !== self::EMPTY_SHA256) {
            return $this->invalid('sha256 does not match an empty file');
        }
        try {
            $rmAfter = Duration::parseWithin($this->optionalString($body, 'rm_after') ?? $this->config->defaultRmAfter, $this->config->maxRmAfter);
            $uses = $this->uses($body);
        } catch (InvalidArgumentException $e) {
            return $this->invalid($e->getMessage());
        }
        if ($this->config->maxFileSize > 0 && $size > $this->config->maxFileSize) {
            return Response::error(413, 'too_large', 'file is larger than the allowed ' . $this->config->maxFileSize . ' bytes');
        }

        $now = ($this->clock)();
        $existing = $this->files->findBySha256($sha256);
        if ($existing instanceof \Linkrot\Files\FileRecord && !$existing->isLive($now)) {
            $this->catalog->remove($existing->id);
            $existing = null;
        }
        if ($existing instanceof \Linkrot\Files\FileRecord) {
            return $this->resumeOrRefuse($existing, $name, $size, $rmAfter, $uses, $now);
        }

        if ($size + $this->files->outstandingBytes() > $this->store->freeSpace()) {
            return Response::error(507, 'insufficient_storage', 'not enough disk space');
        }

        return $this->insert($name, $size, $sha256, $rmAfter, $uses, $now);
    }

    /** GET /api/v1/files/{id} */
    public function show(Request $request, string $id): Response
    {
        $file = $this->live($id);

        return $file instanceof \Linkrot\Files\FileRecord ? Response::json($file->toArray($this->config->baseUrl)) : $this->notFound();
    }

    /** PATCH /api/v1/files/{id}/content */
    public function chunk(Request $request, string $id): Response
    {
        $file = $this->live($id);
        if (!$file instanceof \Linkrot\Files\FileRecord || $file->status !== FileStatus::Uploading) {
            return $this->notFound();
        }
        if (!str_starts_with(strtolower((string) $request->header('content-type')), self::CHUNK_CONTENT_TYPE)) {
            return $this->invalid('Content-Type must be ' . self::CHUNK_CONTENT_TYPE);
        }
        $offsetHeader = $request->header('upload-offset');
        $lengthHeader = $request->header('content-length');
        if ($offsetHeader === null || !ctype_digit($offsetHeader) || $lengthHeader === null || !ctype_digit($lengthHeader)) {
            return $this->invalid('Upload-Offset and Content-Length headers are required');
        }
        $offset = (int) $offsetHeader;
        $length = (int) $lengthHeader - self::DIGEST_LENGTH;

        $handle = @fopen($this->store->path($id), 'r+b');
        if ($handle === false) {
            // The row has no data file (removed behind our back); nothing to resume.
            $this->files->delete($id);

            return $this->notFound();
        }

        try {
            if (!flock($handle, LOCK_EX | LOCK_NB)) {
                return Response::error(409, 'locked', 'another request is writing this upload', headers: ['Retry-After' => '1']);
            }

            // The offset in the database is authoritative and only read inside the lock.
            $file = $this->files->find($id);
            if (!$file instanceof \Linkrot\Files\FileRecord || $file->status !== FileStatus::Uploading) {
                return $this->notFound();
            }
            if ($offset !== $file->uploadOffset) {
                return Response::error(409, 'offset_mismatch', 'Upload-Offset does not match the server', ['offset' => $file->uploadOffset]);
            }
            $expected = min($this->config->chunkSize, $file->size - $offset);
            if ($length !== $expected || $expected < 1) {
                return $this->invalid("chunk must be $expected bytes followed by its 32 byte sha256");
            }

            return $this->writeChunk($request, $handle, $file, $length);
        } finally {
            fclose($handle);
        }
    }

    /** @param resource $handle */
    private function writeChunk(Request $request, mixed $handle, FileRecord $file, int $length): Response
    {
        $input = $request->body();
        if (fseek($handle, $file->uploadOffset) !== 0) {
            return Response::error(500, 'internal', 'cannot seek in data file');
        }
        $copied = @stream_copy_to_stream($input, $handle, $length);
        $digest = $copied === $length ? stream_get_contents($input, self::DIGEST_LENGTH) : false;
        if ($copied !== $length || !@fflush($handle)) {
            return $this->storageFailure($length);
        }
        if ($digest === false || strlen($digest) !== self::DIGEST_LENGTH) {
            return $this->invalid('body is missing the trailing sha256');
        }

        // Verify what was written (hot in the page cache) before the offset moves.
        $context = hash_init('sha256');
        fseek($handle, $file->uploadOffset);
        hash_update_stream($context, $handle, $length);
        if (!hash_equals(hash_final($context, true), $digest)) {
            return Response::error(422, 'chunk_hash_mismatch', 'chunk does not match its sha256, resend it', ['offset' => $file->uploadOffset]);
        }
        if (!@fsync($handle)) {
            return $this->storageFailure($length);
        }

        $now = ($this->clock)();
        $newOffset = $file->uploadOffset + $length;
        if ($newOffset < $file->size) {
            $this->files->setOffset($file->id, $newOffset, $now);

            return Response::json(['offset' => $newOffset]);
        }

        return $this->complete($handle, $file, $now);
    }

    /** @param resource $handle */
    private function complete(mixed $handle, FileRecord $file, int $now): Response
    {
        $context = hash_init('sha256');
        rewind($handle);
        hash_update_stream($context, $handle, $file->size);
        if (!hash_equals($file->sha256, hash_final($context))) {
            $this->catalog->remove($file->id);

            return Response::error(422, 'file_hash_mismatch', 'file does not match the declared sha256, start over');
        }
        $this->files->markReady($file->id, $now);
        $ready = $this->files->find($file->id);

        return $ready instanceof \Linkrot\Files\FileRecord ? Response::json($ready->toArray($this->config->baseUrl)) : $this->notFound();
    }

    private function storageFailure(int $length): Response
    {
        if ($this->store->freeSpace() < $length) {
            return Response::error(507, 'insufficient_storage', 'not enough disk space; the upload can be resumed');
        }

        return $this->invalid('request body is shorter than announced');
    }

    private function resumeOrRefuse(FileRecord $existing, string $name, int $size, int $rmAfter, ?int $uses, int $now): Response
    {
        if ($existing->isReady()) {
            return Response::error(409, 'duplicate', 'this file already exists', [
                'id' => $existing->id,
                'url' => $existing->url($this->config->baseUrl),
            ]);
        }
        if ($existing->size !== $size) {
            return $this->invalid('a different size was announced for this sha256');
        }
        $this->files->updateUploadMetadata($existing->id, $name, $rmAfter, $uses, $now);
        $file = $this->files->find($existing->id) ?? $existing;

        return Response::json($file->toArray($this->config->baseUrl) + ['chunk_size' => $this->config->chunkSize]);
    }

    private function insert(string $name, int $size, string $sha256, int $rmAfter, ?int $uses, int $now): Response
    {
        for ($attempt = 0; $attempt < 5; ++$attempt) {
            $id = FileId::generate();
            $file = new FileRecord($id, $name, $size, $sha256, FileStatus::Uploading, 0, $now, $now, null, $rmAfter, null, $uses);
            try {
                $this->files->insert($file);
            } catch (PDOException $e) {
                // A concurrent create of the same file won the race, or the id collided.
                $winner = $this->files->findBySha256($sha256);
                if ($winner instanceof \Linkrot\Files\FileRecord) {
                    return $this->resumeOrRefuse($winner, $name, $size, $rmAfter, $uses, $now);
                }
                if ($attempt === 4) {
                    throw $e;
                }

                continue;
            }
            try {
                $this->store->create($id, $size);
            } catch (\Throwable $e) {
                $this->files->delete($id);
                throw $e;
            }
            if ($size === 0) {
                $this->files->markReady($id, $now);
            }
            $created = $this->files->find($id) ?? $file;

            return Response::json($created->toArray($this->config->baseUrl) + ['chunk_size' => $this->config->chunkSize], 201);
        }

        throw new \LogicException('unreachable');
    }

    private function live(string $id): ?FileRecord
    {
        return $this->catalog->findLive($id);
    }

    /** @param array<string, mixed> $body */
    private function uses(array $body): ?int
    {
        if (!array_key_exists('uses', $body) || $body['uses'] === null) {
            return $this->config->defaultUses;
        }
        if (!is_int($body['uses']) || $body['uses'] < 1) {
            throw new InvalidArgumentException('uses must be an integer >= 1');
        }

        return $body['uses'];
    }

    /** @param array<string, mixed> $body */
    private function optionalString(array $body, string $key): ?string
    {
        $value = $body[$key] ?? null;
        if ($value !== null && !is_string($value)) {
            throw new InvalidArgumentException("$key must be a string");
        }

        return $value;
    }

    private function invalid(string $message): Response
    {
        return Response::error(400, 'invalid_request', $message);
    }

    private function notFound(): Response
    {
        return Response::error(404, 'not_found', 'not found');
    }
}
