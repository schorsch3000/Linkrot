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

    public function updateMetadata(string $id, string $name, int $rmAfter, ?int $expiresAt, ?int $usesLeft, int $now): void
    {
        $this->pdo->prepare('UPDATE files SET name = ?, rm_after = ?, expires_at = ?, uses_left = ?, updated_at = ? WHERE id = ?')
            ->execute([$name, $rmAfter, $expiresAt, $usesLeft, $now, $id]);
    }

    /** Takes one use, atomically. False if none is left (or the file is unknown). */
    public function consumeUse(string $id): bool
    {
        $update = $this->pdo->prepare('UPDATE files SET uses_left = uses_left - 1 WHERE id = ? AND uses_left > 0');
        $update->execute([$id]);

        return $update->rowCount() === 1;
    }

    /** Moves the offset and stores the running whole-file hash that belongs to it, in one statement. */
    public function setOffset(string $id, int $offset, int $now, ?string $hashState = null): void
    {
        $update = $this->pdo->prepare('UPDATE files SET upload_offset = ?, updated_at = ?, hash_state = ? WHERE id = ?');
        $update->bindValue(1, $offset, PDO::PARAM_INT);
        $update->bindValue(2, $now, PDO::PARAM_INT);
        $update->bindValue(3, $hashState, $hashState === null ? PDO::PARAM_NULL : PDO::PARAM_LOB);
        $update->bindValue(4, $id);
        $update->execute();
    }

    /** The serialized running hash of the bytes before upload_offset, null if there is none (yet). */
    public function hashState(string $id): ?string
    {
        $select = $this->pdo->prepare('SELECT hash_state FROM files WHERE id = ?');
        $select->execute([$id]);
        $state = $select->fetchColumn();

        return is_string($state) && $state !== '' ? $state : null;
    }

    public function markReady(string $id, int $now): void
    {
        $this->pdo->prepare(
            "UPDATE files SET status = 'ready', hash_state = NULL, upload_offset = size, updated_at = :now, completed_at = :now, expires_at = :now + rm_after WHERE id = :id",
        )->execute(['now' => $now, 'id' => $id]);
    }

    /** A streaming upload learns its size and sha256 and becomes a ready file. */
    public function seal(string $id, int $size, string $sha256, int $now): void
    {
        $this->pdo->prepare('UPDATE files SET size = ?, sha256 = ? WHERE id = ?')->execute([$size, $sha256, $id]);
        $this->markReady($id, $now);
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

    /**
     * Bytes the live files occupy or have reserved, for the quota: a sized upload counts with its full size from the
     * start (that is what it will take), a streaming one with what it has received so far. Expired or used up files
     * that the cleanup has not removed yet do not count.
     */
    public function usedBytes(int $now): int
    {
        $statement = $this->pdo->prepare(
            "SELECT COALESCE(SUM(COALESCE(size, upload_offset)), 0) FROM files WHERE status = 'uploading' OR ((expires_at IS NULL OR expires_at > ?) AND (uses_left IS NULL OR uses_left > 0))",
        );
        $statement->execute([$now]);

        return (int) $statement->fetchColumn();
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
