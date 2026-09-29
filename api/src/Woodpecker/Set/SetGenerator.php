<?php

declare(strict_types=1);

namespace App\Woodpecker\Set;

use App\Puzzle\Selection\RandomSeeker;
use App\Woodpecker\Exception\NotEnoughPuzzlesException;

/**
 * Picks the puzzles of a new set: distinct, selectable, within the rating range, with at least one
 * of the themes (if any). Small random index range scans ({@see RandomSeeker}, ~0.1 ms each)
 * until the size is reached: no ORDER BY RAND(), cost proportional to the set size, not to the
 * 5M-row table.
 */
final class SetGenerator
{
    /** Rows per draw: small, so a set spreads over the whole range instead of a few ratings. */
    public const DRAW_SIZE = 20;
    /** Consecutive draws without any new puzzle before concluding the pool is exhausted. */
    public const MAX_STALE_DRAWS = 25;

    public function __construct(private readonly RandomSeeker $seeker)
    {
    }

    /**
     * @param list<int> $themeIds
     *
     * @return list<int> puzzle ids, in the set's order
     *
     * @throws NotEnoughPuzzlesException
     */
    public function generate(SetConfig $config, array $themeIds): array
    {
        $picked = [];
        $stale = 0;
        $maxDraws = intdiv($config->puzzleCount, self::DRAW_SIZE) * 20 + 200;

        for ($draw = 0; \count($picked) < $config->puzzleCount && $draw < $maxDraws && $stale < self::MAX_STALE_DRAWS; ++$draw) {
            $before = \count($picked);
            foreach ($this->seeker->draw($themeIds, $config->ratingMin, $config->ratingMax, self::DRAW_SIZE) as $id) {
                $picked[$id] = true;
            }
            $stale = \count($picked) === $before ? $stale + 1 : 0;
        }

        if (\count($picked) < $config->puzzleCount) {
            throw new NotEnoughPuzzlesException(\count($picked), $config->puzzleCount);
        }

        return \array_slice(array_keys($picked), 0, $config->puzzleCount);
    }
}
