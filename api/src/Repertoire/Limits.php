<?php

declare(strict_types=1);

namespace App\Repertoire;

/**
 * Server-side limits of the repertoire domain (docs/REPERTOIRE.md). One place, so that freemium
 * limits (later) can replace these values per user.
 */
final readonly class Limits
{
    public function __construct(
        public int $maxRepertoires = 50,
        /** Positions per repertoire, the initial one included. */
        public int $maxPositions = 5000,
        /** Plies from the initial position. */
        public int $maxDepth = 80,
        /** Changes that can be undone. */
        public int $undoDepth = 50,
        /** PGN text of an import (file, paste or study). */
        public int $maxImportBytes = 1_048_576,
        /** Games or chapters of an import. */
        public int $maxImportGames = 300,
        /** Imports up to this size are analysed during the request, bigger ones by a worker. */
        public int $syncImportBytes = 102_400,
        /** Imports adding up to this many positions are applied during the request, others by a worker. */
        public int $syncImportPositions = 500,
        /** An import not applied is forgotten after this many hours. */
        public int $importTtlHours = 24,
    ) {
    }
}
