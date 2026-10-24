<?php

declare(strict_types=1);

namespace Linkrot\Files;

use Linkrot\FileName;

final readonly class FileRecord
{
    public function __construct(
        public string $id,
        public string $name,
        public int $size,
        public string $sha256,
        public FileStatus $status,
        public int $uploadOffset,
        public int $createdAt,
        public int $updatedAt,
        public ?int $completedAt,
        public int $rmAfter,
        public ?int $expiresAt,
        public ?int $usesLeft,
    ) {}

    /** @param array<array-key, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            id: (string) $row['id'],
            name: (string) $row['name'],
            size: (int) $row['size'],
            sha256: (string) $row['sha256'],
            status: FileStatus::from((string) $row['status']),
            uploadOffset: (int) $row['upload_offset'],
            createdAt: (int) $row['created_at'],
            updatedAt: (int) $row['updated_at'],
            completedAt: $row['completed_at'] === null ? null : (int) $row['completed_at'],
            rmAfter: (int) $row['rm_after'],
            expiresAt: $row['expires_at'] === null ? null : (int) $row['expires_at'],
            usesLeft: $row['uses_left'] === null ? null : (int) $row['uses_left'],
        );
    }

    public function isReady(): bool
    {
        return $this->status === FileStatus::Ready;
    }

    /** Incomplete uploads are alive until the cleanup removes them; completed files until expiry or the last use. */
    public function isLive(int $now): bool
    {
        if ($this->status === FileStatus::Uploading) {
            return true;
        }

        return ($this->expiresAt === null || $this->expiresAt > $now) && $this->usesLeft !== 0;
    }

    public function url(string $baseUrl): string
    {
        return rtrim($baseUrl, '/') . '/file/' . $this->id . '/' . FileName::urlSegment($this->name);
    }

    /** @return array<string, mixed> the file object of docs/API.md */
    public function toArray(string $baseUrl): array
    {
        $data = [
            'id' => $this->id,
            'name' => $this->name,
            'size' => $this->size,
            'sha256' => $this->sha256,
            'status' => $this->status->value,
            'offset' => $this->uploadOffset,
            'created_at' => $this->createdAt,
            'completed_at' => $this->completedAt,
            'expires_at' => $this->expiresAt,
            'uses_left' => $this->usesLeft,
        ];
        if ($this->isReady()) {
            $data['url'] = $this->url($baseUrl);
        }

        return $data;
    }
}
