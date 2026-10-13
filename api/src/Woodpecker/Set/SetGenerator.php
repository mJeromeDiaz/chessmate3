<?php

declare(strict_types=1);

namespace App\Woodpecker\Set;

use App\Puzzle\Selection\RandomSeeker;
use App\Woodpecker\Exception\NotEnoughPuzzlesException;

/**
 * Picks the puzzles of a set: distinct, selectable, within the rating range, from its {@see Pool}
 * (at least one of the themes, if any; or an opening family). Small random index range scans ({@see RandomSeeker}, ~0.1 ms each) until
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
     * @return list<int> puzzle ids, in the set's order
     *
     * @throws NotEnoughPuzzlesException
     */
    public function generate(int $count, int $ratingMin, int $ratingMax, Pool $pool): array
    {
        $picked = $this->pick($count, $ratingMin, $ratingMax, $pool, []);
        if (\count($picked) < $count) {
            throw new NotEnoughPuzzlesException(\count($picked), $count);
        }

        return $picked;
    }

    /**
     * Puzzles to append to a set: up to $count, none of $exclude (the set's current list); fewer,
     * possibly none, when the pool runs out.
     *
     * @param list<int> $exclude
     *
     * @return list<int> puzzle ids, in the order to append them
     */
    public function extend(int $count, int $ratingMin, int $ratingMax, Pool $pool, array $exclude): array
    {
        return $this->pick($count, $ratingMin, $ratingMax, $pool, $exclude);
    }

    /**
     * @param list<int> $exclude
     *
     * @return list<int>
     */
    private function pick(int $count, int $ratingMin, int $ratingMax, Pool $pool, array $exclude): array
    {
        $excluded = array_fill_keys($exclude, true);
        $picked = [];
        $stale = 0;
        $maxDraws = intdiv($count, self::DRAW_SIZE) * 20 + 200;

        for ($draw = 0; \count($picked) < $count && $draw < $maxDraws && $stale < self::MAX_STALE_DRAWS; ++$draw) {
            $before = \count($picked);
            $drawn = null === $pool->opening
                ? $this->seeker->draw($pool->themeIds, $ratingMin, $ratingMax, self::DRAW_SIZE)
                : $this->seeker->drawOpening($pool->opening->family, $pool->opening->solverColor(), $ratingMin, $ratingMax, self::DRAW_SIZE);
            foreach ($drawn as $id) {
                if (!isset($excluded[$id])) {
                    $picked[$id] = true;
                }
            }
            $stale = \count($picked) === $before ? $stale + 1 : 0;
        }

        return \array_slice(array_keys($picked), 0, $count);
    }
}
