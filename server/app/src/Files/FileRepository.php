<?php

declare(strict_types=1);

namespace Linkrot\Files;

use Linkrot\Database;
use PDO;

final readonly class FileRepository
{
    public function __construct(private PDO $pdo) {}

    public function find(string $id): ?FileRecord
    {
        return $this->one('SELECT * FROM files WHERE id = ?', [$id]);
    }

    public function findBySha256(string $sha256): ?FileRecord
    {
        return $this->one('SELECT * FROM files WHERE sha256 = ?', [$sha256]);
    }

    /**
     * Inserts a new record.
     *
     * @throws \PDOException on id or sha256 conflict
     */
    public function insert(FileRecord $file): void
    {
        $this->pdo->prepare(
            'INSERT INTO files (id, name, size, sha256, status, upload_offset, created_at, updated_at, completed_at, rm_after, expires_at, uses_left)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        )->execute([
            $file->id, $file->name, $file->size, $file->sha256, $file->status->value, $file->uploadOffset,
            $file->createdAt, $file->updatedAt, $file->completedAt, $file->rmAfter, $file->expiresAt, $file->usesLeft,
        ]);
    }

    public function delete(string $id): bool
    {
        $delete = $this->pdo->prepare('DELETE FROM files WHERE id = ?');
        $delete->execute([$id]);

        return $delete->rowCount() === 1;
    }

    /** Metadata of an incomplete upload that is being resumed with a new create request. */
    public function updateUploadMetadata(string $id, string $name, int $rmAfter, ?int $usesLeft, int $now): void
    {
        $this->pdo->prepare('UPDATE files SET name = ?, rm_after = ?, uses_left = ?, updated_at = ? WHERE id = ?')
            ->execute([$name, $rmAfter, $usesLeft, $now, $id]);
    }

    public function setOffset(string $id, int $offset, int $now): void
    {
        $this->pdo->prepare('UPDATE files SET upload_offset = ?, updated_at = ? WHERE id = ?')->execute([$offset, $now, $id]);
    }

    public function markReady(string $id, int $now): void
    {
        $this->pdo->prepare(
            "UPDATE files SET status = 'ready', upload_offset = size, updated_at = :now, completed_at = :now, expires_at = :now + rm_after WHERE id = :id",
        )->execute(['now' => $now, 'id' => $id]);
    }

    /** @return list<FileRecord> live files, newest first */
    public function listLive(int $now): array
    {
        $statement = $this->pdo->prepare(
            "SELECT * FROM files WHERE status = 'uploading' OR ((expires_at IS NULL OR expires_at > ?) AND (uses_left IS NULL OR uses_left > 0)) ORDER BY created_at DESC, id",
        );
        $statement->execute([$now]);

        return array_values(array_map(FileRecord::fromRow(...), $statement->fetchAll()));
    }

    /** Bytes that running uploads still have to receive; used for the disk space check. */
    public function outstandingBytes(): int
    {
        return (int) Database::query($this->pdo, "SELECT COALESCE(SUM(size - upload_offset), 0) FROM files WHERE status = 'uploading'")->fetchColumn();
    }

    /** @param list<int|string> $params */
    private function one(string $sql, array $params): ?FileRecord
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        $row = $statement->fetch();

        return is_array($row) ? FileRecord::fromRow($row) : null;
    }
}
