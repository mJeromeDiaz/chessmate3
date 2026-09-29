<?php

declare(strict_types=1);

namespace App\Woodpecker\Cycle;

use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * The order of a set's puzzles in one cycle run: the set's own order, or a permutation derived
 * from the run's seed (deterministic, so nothing but the seed is stored).
 */
final class CycleOrder
{
    /**
     * @return list<int> set positions, in playing order
     */
    public static function positions(int $puzzleCount, int $seed, bool $shuffle): array
    {
        $positions = range(0, $puzzleCount - 1);

        return $shuffle ? (new Randomizer(new Mt19937($seed)))->shuffleArray($positions) : $positions;
    }

    public static function newSeed(): int
    {
        return random_int(0, 0x7FFFFFFF);
    }
}
