<?php

declare(strict_types=1);

namespace App\Repertoire\Training;

use Random\Randomizer;

/**
 * The order of the units in a round of a repertoire test (docs/REPERTOIRE.md):
 *
 * 1. units with a card already answered and due, the longest overdue first;
 * 2. units whose last presentation failed;
 * 3. the others, the least presented first (new units, never presented, lead);
 *
 * ties drawn at random. A new round does not start with the unit just played.
 */
final readonly class UnitQueue
{
    /** A failed segment comes back after this many other units (a line at once). */
    public const RETRY_AFTER = 3;

    private Randomizer $random;

    public function __construct(?Randomizer $random = null)
    {
        $this->random = $random ?? new Randomizer();
    }

    /**
     * @param list<UnitStanding> $units
     *
     * @return list<UnitStanding>
     */
    public function order(array $units, ?string $lastKey = null): array
    {
        $units = $this->random->shuffleArray($units);
        // usort is stable: the shuffle breaks the ties.
        usort($units, static fn (UnitStanding $a, UnitStanding $b): int => self::rank($a) <=> self::rank($b));
        if (\count($units) > 1 && $units[0]->key === $lastKey) {
            [$units[0], $units[1]] = [$units[1], $units[0]];
        }

        return $units;
    }

    /**
     * Where a failed unit goes back in the queue of the round.
     */
    public static function retryIndex(int $queued, bool $line): int
    {
        return $line ? 0 : min(self::RETRY_AFTER, $queued);
    }

    /**
     * @return list<int|float>
     */
    private static function rank(UnitStanding $unit): array
    {
        return match (true) {
            null !== $unit->overdueSince => [1, (float) $unit->overdueSince->format('U.u')],
            $unit->lastFailed => [2, 0],
            default => [3, $unit->presentations],
        };
    }
}
