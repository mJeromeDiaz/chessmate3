<?php

declare(strict_types=1);

namespace App\Enum\Repertoire;

/**
 * Learning state of a spaced-repetition card (FSRS, docs/REPERTOIRE.md). Values of py-fsrs.
 * Stored as its value: add cases, never renumber one.
 */
enum CardState: int
{
    /** New, or going through the learning steps (minutes apart). */
    case Learning = 1;
    /** Scheduled in days. */
    case Review = 2;
    /** Forgotten in review: back through the relearning steps. */
    case Relearning = 3;
}
