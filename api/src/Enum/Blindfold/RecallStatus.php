<?php

declare(strict_types=1);

namespace App\Enum\Blindfold;

/**
 * Where a position to rebuild stands (docs/BLINDFOLD_RECALL.md). Stored as its value: add cases,
 * never rename one.
 */
enum RecallStatus: string
{
    /** Served, not submitted yet. */
    case Pending = 'pending';
    /** Every piece on its square, none extra. */
    case Exact = 'exact';
    /** Not exact, but accurate enough ({@see \App\Blindfold\Recall\RecallRules::CLOSE_ACCURACY}). */
    case Close = 'close';
    case Missed = 'missed';
}
