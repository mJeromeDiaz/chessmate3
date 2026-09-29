<?php

declare(strict_types=1);

namespace App\Woodpecker\Set;

/**
 * The frozen parameters of a set (validated by the API input before reaching here).
 */
final readonly class SetConfig
{
    /**
     * @param list<string> $themes theme keys (OR); empty = any theme
     */
    public function __construct(
        public int $puzzleCount,
        public int $ratingMin,
        public int $ratingMax,
        public array $themes,
        public int $cycleCount,
        public int $firstCycleDays,
        public float $reductionFactor,
        public int $minCycleDays,
        public int $restDays,
        public bool $shuffle,
    ) {
    }
}
