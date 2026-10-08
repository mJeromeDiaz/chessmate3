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
    /** A segment presented in a repertoire test (a line gives one per segment it crosses). */
    case RepertoireSegment = 'repertoire_segment';
    /** Free study timed in a run (a book, a video...): its real duration. */
    case FreeStudy = 'free_study';
    /** A coordinates series (docs/COORDINATES.md): its answers are its items. */
    case CoordinatesSeries = 'coordinates_series';
    /** A puzzle solved from memory (docs/BLINDFOLD.md); `metadata.status`: solved, helped or failed. */
    case BlindfoldPuzzle = 'blindfold_puzzle';
    /**
     * A position evaluated (docs/EVALUATION.md); `metadata.status` (exact, close, miss, timeout),
     * `metadata.planOk`, `metadata.fast`.
     */
    case PositionEvaluation = 'position_evaluation';
}
