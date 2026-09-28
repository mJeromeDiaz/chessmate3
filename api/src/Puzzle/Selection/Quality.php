<?php

declare(strict_types=1);

namespace App\Puzzle\Selection;

/**
 * Quality thresholds a puzzle must pass to be served (validated 2026-09-28): popularity weeds out
 * puzzles players voted down (ambiguous or broken), the play count out puzzles whose rating has not
 * settled yet. The import (docs/PUZZLE_IMPORT.md) and `app:puzzle:rebuild-selection` use the same
 * values: change them here, then run the command.
 */
final class Quality
{
    public const MIN_POPULARITY = 50;
    public const MIN_PLAYS = 100;

    public static function isSelectable(int $popularity, int $nbPlays): bool
    {
        return $popularity >= self::MIN_POPULARITY && $nbPlays >= self::MIN_PLAYS;
    }
}
