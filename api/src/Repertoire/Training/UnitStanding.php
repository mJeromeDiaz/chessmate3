<?php

declare(strict_types=1);

namespace App\Repertoire\Training;

/**
 * What the queue of a repertoire test orders a unit by ({@see UnitQueue}).
 */
final readonly class UnitStanding
{
    public function __construct(
        public string $repertoireId,
        public string $key,
        /** The oldest due date among its cards already answered and due now; null when none is. */
        public ?\DateTimeImmutable $overdueSince,
        /** Its last presentation (of any of its segments, for a line) failed. */
        public bool $lastFailed,
        /** Presentations finished (for a line: the least presented of its segments). */
        public int $presentations,
    ) {
    }
}
