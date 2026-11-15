<?php

declare(strict_types=1);

namespace Linkrot\Files;

use Closure;
use Linkrot\FileId;

/**
 * Looks files up the way the API sees them: over its time counts as not found, and is removed on the spot. A file whose
 * last use is gone is not found either, but it stays: the download that took the last use may not have been opened by
 * nginx yet, and unlinking now would break it. The cleanup removes it after a grace period.
 */
final readonly class FileCatalog
{
    /** @param Closure(): int $clock */
    public function __construct(
        private FileRepository $files,
        private FileStore $store,
        private Closure $clock,
    ) {}

    public function findLive(string $id): ?FileRecord
    {
        if (!FileId::isValid($id)) {
            return null;
        }
        $file = $this->files->find($id);
        if (!$file instanceof \Linkrot\Files\FileRecord) {
            return null;
        }
        if (!$file->isLive(($this->clock)())) {
            if (!$file->isExhausted()) {
                $this->remove($id);
            }

            return null;
        }

        return $file;
    }

    /**
     * A file whose last use is gone but was counted less than `$window` seconds ago: the download that took it may
     * be resumed. Anything else answers null like findLive.
     */
    public function findResumable(string $id, int $window): ?FileRecord
    {
        if (!FileId::isValid($id)) {
            return null;
        }
        $file = $this->files->find($id);
        $now = ($this->clock)();
        if (!$file instanceof FileRecord || !$file->isReady() || !$file->isExhausted() || $file->lastUseAt === null) {
            return null;
        }
        if ($file->expiresAt !== null && $file->expiresAt <= $now) {
            return null;
        }

        return $this->isWithinResumeWindow($file, $window) ? $file : null;
    }

    /** Was a use counted less than `$window` seconds ago? Then the continuation of that download is free. */
    public function isWithinResumeWindow(FileRecord $file, int $window): bool
    {
        return $file->lastUseAt !== null && ($this->clock)() - $file->lastUseAt < $window;
    }

    /** Takes one use, atomically, and notes when (the cleanup waits a grace period after the last one). False if none is left. */
    public function consumeUse(string $id): bool
    {
        return $this->files->consumeUse($id, ($this->clock)());
    }

    /** Removes row and data file. */
    public function remove(string $id): void
    {
        $this->files->delete($id);
        $this->store->delete($id);
    }
}
