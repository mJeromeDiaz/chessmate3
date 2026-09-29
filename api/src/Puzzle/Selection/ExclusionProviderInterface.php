<?php

declare(strict_types=1);

namespace App\Puzzle\Selection;

use App\Entity\User;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Puzzles another domain wants kept out of the user's rated selection (e.g. the puzzles of an
 * active Woodpecker set: seen again and again, they would inflate the rating). Autoconfigured:
 * the Puzzle domain never depends on the domains that implement it.
 */
#[AutoconfigureTag(self::TAG)]
interface ExclusionProviderInterface
{
    public const TAG = 'app.puzzle.selection_exclusion';

    /**
     * Must stay an indexed probe: called on every selection with ~30 candidate ids.
     *
     * @param list<int> $puzzleIds
     *
     * @return list<int> the excluded ones among $puzzleIds
     */
    public function excludedAmong(User $user, array $puzzleIds): array;
}
