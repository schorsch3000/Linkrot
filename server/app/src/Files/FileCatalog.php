<?php

declare(strict_types=1);

namespace Linkrot\Files;

use Closure;
use Linkrot\FileId;

/** Looks files up the way the API sees them: over its time counts as not found, and is removed on the spot. */
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
            $this->remove($id);

            return null;
        }

        return $file;
    }

    /** Removes row and data file. */
    public function remove(string $id): void
    {
        $this->files->delete($id);
        $this->store->delete($id);
    }
}
