<?php

declare(strict_types=1);

namespace App\Vision;

/**
 * The rules of the vision module (docs/VISION.md).
 */
final class VisionRules
{
    /** `locate`: the position recall's level whose pieces are drawn (2 kings, a rook, 5 pawns). */
    public const LOCATE_LEVEL = 8;
    /** `locate`: how long the position shows. */
    public const LOCATE_SECONDS = 12;
    /** `en_prise`, `attackers`: how long the puzzle's position shows. */
    public const PUZZLE_SECONDS = 15;
    /** `en_prise`, `attackers`: the pieces of the position shown (after the opponent's first move). */
    public const PUZZLE_PIECES = [6, 12];
    /** `en_prise`, `attackers`: the moves of the solution announced once the position is hidden. */
    public const ANNOUNCED = 3;
    /** A piece's path (chapter 3.1): how long its position shows. */
    public const TRACK_SECONDS = 8;
    /** A position updated by moves (chapter 3.2): how long it shows, its pieces besides the kings. */
    public const UPDATE_SECONDS = 10;
    public const UPDATE_MISSING_PIECES = ['?N', '?B', '?P', '?P', '?P', '?P'];
    public const UPDATE_CAPTURES_PIECES = ['?R', '?N', '?B', '?P', '?P', '?P', '?P'];
    public const UPDATE_MIXED_PIECES = ['?R', '?N', '?B', '?P', '?P', '?P', '?P', '?P'];
    public const UPDATE_TRIES = 300;
    /** The pause, position hidden, before the moves or the question. */
    public const HIDDEN_SECONDS = 2;
    /** The squares an answer may click (the attackers of a square: 16 at most). */
    public const MAX_SQUARES = 16;
    /** The puzzles drawn (only the position counts). */
    public const PUZZLE_RATINGS = [600, 2600];
    public const CANDIDATES = 30;
    public const DRAWS = 4;
    /** A run's questions and lives at most. */
    public const MAX_COUNT = 20;
    public const MAX_LIVES = 10;
    /** A question's time counted in the run's active time at most. */
    public const ACTIVE_TIME_CAP_MS = 300_000;
}
