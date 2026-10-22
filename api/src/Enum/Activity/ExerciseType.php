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
    /**
     * A puzzle of a streak (docs/PUZZLE_STREAK.md); `metadata.status` (solved, failed),
     * `metadata.rating` (the puzzle's: the XP depends on it).
     */
    case StreakPuzzle = 'streak_puzzle';
    /**
     * A puzzle of a parcours (docs/PARCOURS.md), never rated; `metadata.status` (solved, failed),
     * `metadata.rating` (the puzzle's: the XP depends on it, as for a streak puzzle).
     */
    case ParcoursPuzzle = 'parcours_puzzle';
    /**
     * A puzzle rush (docs/PUZZLE_RUSH.md), one per run, logged when it closes: its puzzles played
     * are its items; `metadata.solved`, `metadata.failed` (the XP depends on them).
     */
    case PuzzleRush = 'puzzle_rush';
    /**
     * A position rebuilt from memory (docs/BLINDFOLD_RECALL.md); `metadata.status` (exact, close,
     * missed), `metadata.stage` and `metadata.unlocked` (the XP depends on them).
     */
    case BlindfoldRecall = 'blindfold_recall';
    /**
     * An item of the notation module (docs/NOTATION.md), never rated; `metadata.kind` (play,
     * sequence, write), `metadata.status` (right, wrong).
     */
    case Notation = 'notation';
    /**
     * A fight against the Jongleur (docs/MIND_MAP.md, § 8), one per run, logged when it closes: its
     * questions answered are its items; `metadata.right`, `metadata.wrong`, `metadata.won`. No XP of
     * its own (the boss's reward).
     */
    case Jongleur = 'jongleur';
    /**
     * A question of the vision module (docs/VISION.md), never rated; `metadata.kind` (locate,
     * en_prise, attackers), `metadata.ask`, `metadata.status` (right, wrong).
     */
    case Vision = 'vision';
    /**
     * A line of the calculation module (docs/CALCULATION.md), never rated; `metadata.mate` (the
     * line's moves), `metadata.ending` (mate, material), `metadata.status` (right, wrong, late),
     * `metadata.boss` (the Métronome: no XP).
     */
    case Calculation = 'calculation';
    /**
     * A position of the endgames module (docs/ENDGAMES.md), never rated; `metadata.status` (won,
     * held, spoiled, overtime, resigned), `metadata.lesson`, `metadata.opponentElo` (null: the
     * tablebase's best defence; the XP depends on it).
     */
    case Endgame = 'endgame';
}
