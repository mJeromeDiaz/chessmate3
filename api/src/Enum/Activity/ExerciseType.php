<?php

declare(strict_types=1);

namespace App\Enum\Activity;

/**
 * Kind of completed exercise (docs/ACTIVITY.md). Stored as its value: add cases, never rename one.
 */
enum ExerciseType: string
{
    case PuzzleRated = 'puzzle_rated';
    case PuzzleUnrated = 'puzzle_unrated';
    case WoodpeckerPuzzle = 'woodpecker_puzzle';
}
