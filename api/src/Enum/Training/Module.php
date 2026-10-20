<?php

declare(strict_types=1);

namespace App\Enum\Training;

/**
 * A module that can be played in timed runs (docs/TRAINING.md). Stored as its value: add cases,
 * never rename one.
 */
enum Module: string
{
    case Woodpecker = 'woodpecker';
    case Repertoire = 'repertoire';
    case Puzzles = 'puzzles';
    /** Free study (a book, a video...): a timer, nothing to submit. */
    case Free = 'free';
    /** Coordinates series: find the squares named on an empty board (docs/COORDINATES.md). */
    case Coordinates = 'coordinates';
    /** Blindfold puzzles: shown, hidden, solved from memory (docs/BLINDFOLD.md). */
    case Blindfold = 'blindfold';
    /** Position evaluation: who stands better, and the plan (docs/EVALUATION.md). */
    case Evaluation = 'evaluation';
    /** Puzzle streak: easy to hard, one miss breaks it (docs/PUZZLE_STREAK.md). */
    case PuzzleStreak = 'puzzle_streak';
    /** A tier of puzzles of a parcours, ended by its count (docs/PARCOURS.md); never in a hand-made session. */
    case ParcoursPuzzles = 'parcours_puzzles';
    /** Puzzle rush: as many puzzles as possible in 2, 3 or 5 minutes, a miss costs two (docs/PUZZLE_RUSH.md). */
    case PuzzleRush = 'puzzle_rush';
    /** Position recall: a position shown, hidden, then rebuilt piece by piece (docs/BLINDFOLD_RECALL.md). */
    case BlindfoldRecall = 'blindfold_recall';
    /** Notation: a move announced to play, a sequence to play for both sides, a puzzle's line to write (docs/NOTATION.md). */
    case Notation = 'notation';
    /** The Jongleur, a mind map boss: quick mixed questions of 6 s, 3 lives (docs/MIND_MAP.md, § 8); played from its path only. */
    case Jongleur = 'jongleur';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $module): string => $module->value, self::cases());
    }
}
