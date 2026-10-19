<?php

declare(strict_types=1);

namespace App\Blindfold\Recall;

use App\Enum\Blindfold\RecallStatus;

/**
 * A rebuild corrected square by square ({@see Grader}): pieces on their square ($correct), pieces
 * placed where another one or none stands ($wrong, $extra the latter), pieces of the position not
 * found on their square ($missing).
 */
final readonly class Grade
{
    public function __construct(
        public int $expected,
        public int $placed,
        public int $correct,
        public int $wrong,
        public int $extra,
        public int $missing,
        public float $accuracy,
        public RecallStatus $status,
    ) {
    }
}
