<?php

declare(strict_types=1);

namespace App\Woodpecker\Set;

use App\Puzzle\Selection\RandomSeeker;
use App\Woodpecker\Exception\NotEnoughPuzzlesException;

/**
 * Picks the puzzles of a set: distinct, selectable, within the rating range, with at least one of
 * the themes (if any). Small random index range scans ({@see RandomSeeker}, ~0.1 ms each) until
 * the size is reached: no ORDER BY RAND(), cost proportional to the set size, not to the 5M-row
 * table.
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
     * The list of a new set: exactly $count puzzles.
     *
     * @param list<int> $themeIds
     *
     * @return list<int> puzzle ids, in the set's order
     *
     * @throws NotEnoughPuzzlesException
     */
    public function generate(int $count, int $ratingMin, int $ratingMax, array $themeIds): array
    {
        $picked = $this->pick($count, $ratingMin, $ratingMax, $themeIds, []);
        if (\count($picked) < $count) {
            throw new NotEnoughPuzzlesException(\count($picked), $count);
        }

        return $picked;
    }

    /**
     * Puzzles to append to a set: up to $count, none of $exclude (the set's current list); fewer,
     * possibly none, when the pool runs out.
     *
     * @param list<int> $themeIds
     * @param list<int> $exclude
     *
     * @return list<int> puzzle ids, in the order to append them
     */
    public function extend(int $count, int $ratingMin, int $ratingMax, array $themeIds, array $exclude): array
    {
        return $this->pick($count, $ratingMin, $ratingMax, $themeIds, $exclude);
    }

    /**
     * @param list<int> $themeIds
     * @param list<int> $exclude
     *
     * @return list<int>
     */
    private function pick(int $count, int $ratingMin, int $ratingMax, array $themeIds, array $exclude): array
    {
        $excluded = array_fill_keys($exclude, true);
        $picked = [];
        $stale = 0;
        $maxDraws = intdiv($count, self::DRAW_SIZE) * 20 + 200;

        for ($draw = 0; \count($picked) < $count && $draw < $maxDraws && $stale < self::MAX_STALE_DRAWS; ++$draw) {
            $before = \count($picked);
            foreach ($this->seeker->draw($themeIds, $ratingMin, $ratingMax, self::DRAW_SIZE) as $id) {
                if (!isset($excluded[$id])) {
                    $picked[$id] = true;
                }
            }
            $stale = \count($picked) === $before ? $stale + 1 : 0;
        }

        return \array_slice(array_keys($picked), 0, $count);
    }
}
