<?php

declare(strict_types=1);

namespace App\Notation;

/**
 * The rules of the notation module (docs/NOTATION.md, § 2).
 */
final class NotationRules
{
    /** The puzzles drawn: easy enough to be read, for every exercise. */
    public const PUZZLE_RATINGS = [800, 1800];
    /** Tries of a draw, candidates read by try. */
    public const DRAWS = 4;
    public const CANDIDATES = 12;
    /** A sequence to play: its half-moves (the puzzle's solution, cut at MAX). */
    public const SEQUENCE_MIN_PLIES = 4;
    public const SEQUENCE_MAX_PLIES = 8;
    /** A line to write: the puzzle's whole solution, at most this many half-moves. */
    public const WRITE_MAX_PLIES = 7;
    /** A repertoire for the line exercise: at least this many segments (docs/REPERTOIRE.md, § 6). */
    public const LINE_MIN_SEGMENTS = 10;
    /** A line to complete: its last half-moves to write, and its length at least. */
    public const LINE_WRITE_PLIES = 4;
    public const LINE_MIN_PLIES = 6;
    /** A legendary game: its half-moves by item, played (or written) from where the last item ended. */
    public const GAME_CHUNK_PLIES = 6;
    /** A counted run (a mind map lesson, docs/MIND_MAP.md): its items, at most. */
    public const MAX_COUNT = 30;
    /** Half-moves written, at most (a submission). */
    public const MAX_WRITTEN = 16;
    /** Characters of a written string, at most (a whole line fits: numbers, marks and spaces included). */
    public const MAX_WRITTEN_LENGTH = 200;
    /** An item's time counted in the run's active time, at most (a pause does not count). */
    public const ACTIVE_TIME_CAP_MS = 300_000;
}
