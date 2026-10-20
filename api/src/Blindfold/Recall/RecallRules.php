<?php

declare(strict_types=1);

namespace App\Blindfold\Recall;

use App\Enum\Blindfold\RecallStatus;

/**
 * The rules of position recall (docs/BLINDFOLD_RECALL.md), in one place: change a value here and
 * the API, the front (it reads them from GET /blindfold/recall) and the selection follow. Pure.
 *
 * A position is shown for its level's time, hidden for HIDDEN_SECONDS, then rebuilt piece by piece
 * on an empty board. Twenty levels in five stages: positions generated with a few pieces first
 * (both kings always, plus the level's pieces), then real puzzle positions, then a puzzle position
 * followed by moves announced once it is hidden (the position to rebuild is the one after them).
 *
 * A generated level lists its pieces besides the kings: "P" a white pawn, "p" a black one, "?P" a
 * pawn of either side; several letters ("?BB") are pieces of one side, two bishops of it on squares
 * of both colours.
 */
final class RecallRules
{
    public const MAX_LEVEL = 20;

    /** @var array<int, array{stage: int, visibleSeconds: int, pieces?: list<string>, puzzlePieces?: array{int, int}, announced?: int}> */
    public const LEVELS = [
        // Stage 1: squares and pieces.
        1 => ['stage' => 1, 'visibleSeconds' => 8, 'pieces' => ['P']],
        2 => ['stage' => 1, 'visibleSeconds' => 8, 'pieces' => ['P', 'P']],
        3 => ['stage' => 1, 'visibleSeconds' => 8, 'pieces' => ['P', 'P', 'P', 'p']],
        4 => ['stage' => 1, 'visibleSeconds' => 8, 'pieces' => ['B', '?P', '?P', '?P']],
        // Stage 2: groups of pieces.
        5 => ['stage' => 2, 'visibleSeconds' => 12, 'pieces' => ['?B', '?P', '?P', '?P', '?P']],
        6 => ['stage' => 2, 'visibleSeconds' => 12, 'pieces' => ['?BB', '?P', '?P', '?P', '?P']],
        7 => ['stage' => 2, 'visibleSeconds' => 12, 'pieces' => ['?N', '?P', '?P', '?P', '?P']],
        8 => ['stage' => 2, 'visibleSeconds' => 12, 'pieces' => ['?R', '?P', '?P', '?P', '?P', '?P']],
        // Stage 3: denser positions.
        9 => ['stage' => 3, 'visibleSeconds' => 15, 'pieces' => ['?Q', '?P', '?P', '?P', '?P', '?P']],
        10 => ['stage' => 3, 'visibleSeconds' => 15, 'pieces' => ['?B', '?N', '?P', '?P', '?P', '?P', '?P', '?P']],
        11 => ['stage' => 3, 'visibleSeconds' => 15, 'pieces' => ['?R', '?R', '?P', '?P', '?P', '?P', '?P', '?P']],
        12 => ['stage' => 3, 'visibleSeconds' => 15, 'pieces' => ['?Q', '?B', '?N', '?P', '?P', '?P', '?P', '?P', '?P']],
        // Stage 4: real positions (puzzles), by their number of pieces.
        13 => ['stage' => 4, 'visibleSeconds' => 15, 'puzzlePieces' => [14, 14]],
        14 => ['stage' => 4, 'visibleSeconds' => 15, 'puzzlePieces' => [16, 16]],
        15 => ['stage' => 4, 'visibleSeconds' => 20, 'puzzlePieces' => [18, 18]],
        16 => ['stage' => 4, 'visibleSeconds' => 20, 'puzzlePieces' => [20, 20]],
        // Stage 5: less time, then moves announced once the position is hidden.
        17 => ['stage' => 5, 'visibleSeconds' => 5, 'puzzlePieces' => [12, 16]],
        18 => ['stage' => 5, 'visibleSeconds' => 5, 'puzzlePieces' => [16, 20]],
        19 => ['stage' => 5, 'visibleSeconds' => 15, 'puzzlePieces' => [12, 16], 'announced' => 1],
        20 => ['stage' => 5, 'visibleSeconds' => 15, 'puzzlePieces' => [12, 16], 'announced' => 3],
    ];

    /** Pause, position hidden, before rebuilding it. */
    public const HIDDEN_SECONDS = 3;
    /** Exact rebuilds at a level that open the next one. */
    public const UNLOCK_EXACT = 3;
    /** A rebuild that is not exact is "close" from this accuracy. */
    public const CLOSE_ACCURACY = 0.8;
    /** The free mode (the recall page): one long run, its clock hidden. */
    public const FREE_BUDGET_SECONDS = 3600;
    /** Pieces a rebuild may place (a full board). */
    public const MAX_PLACED = 64;

    /** Puzzle ratings drawn from: any reasonable puzzle, only its position matters. */
    public const PUZZLE_RATINGS = [600, 2600];
    /** Puzzles already rebuilt by the user are left out of a draw while others remain. */
    public const CANDIDATES = 30;
    public const DRAWS = 4;
    /** Placements tried before giving up on a generated position (never reached in practice). */
    public const GENERATION_TRIES = 500;
    /** A rebuild's time counts for at most this much in the run's active time (a tab left open). */
    public const ACTIVE_TIME_CAP_MS = 300_000;
    /** A mind map lesson's or boss's positions at most (docs/BLINDFOLD_RECALL.md, § 3 bis). */
    public const MAX_COUNT = 20;
    /** A mind map boss's lives at most, counted in pieces wrong or missing. */
    public const MAX_LIVES = 10;
    /** A mind map step's own time to memorize, in seconds (the Cyclope: 30). */
    public const PATH_VISIBLE_SECONDS = [5, 60];
    /** A mind map step's moves announced after a generated position, at most (chapter 3.2). */
    public const MAX_ANNOUNCE = 5;

    public static function exists(int $level): bool
    {
        return isset(self::LEVELS[$level]);
    }

    /**
     * A level's rules, every key present: `pieces` empty and `puzzlePieces` set for a puzzle level.
     *
     * @return array{stage: int, visibleSeconds: int, pieces: list<string>, puzzlePieces: array{int, int}|null, announced: int}
     */
    public static function level(int $level): array
    {
        $rules = self::LEVELS[$level] ?? throw new \InvalidArgumentException(\sprintf('Unknown level %d.', $level));

        return [
            'stage' => $rules['stage'],
            'visibleSeconds' => $rules['visibleSeconds'],
            'pieces' => $rules['pieces'] ?? [],
            'puzzlePieces' => $rules['puzzlePieces'] ?? null,
            'announced' => $rules['announced'] ?? 0,
        ];
    }

    public static function stage(int $level): int
    {
        return self::level($level)['stage'];
    }

    /**
     * Pieces placed among those to place and those placed in excess, rounded to 1 %: a wrong piece
     * on a square counts against it once, an extra piece once more.
     */
    public static function accuracy(int $correct, int $expected, int $extra): float
    {
        $total = $expected + $extra;

        return $total > 0 ? round($correct / $total, 2) : 1.0;
    }

    public static function status(int $correct, int $expected, int $placed, float $accuracy): RecallStatus
    {
        return match (true) {
            $correct === $expected && $placed === $expected => RecallStatus::Exact,
            $accuracy >= self::CLOSE_ACCURACY => RecallStatus::Close,
            default => RecallStatus::Missed,
        };
    }

    /**
     * The rules as the front reads them.
     *
     * @return array{levels: list<array{level: int, stage: int, visibleSeconds: int, pieces: list<string>, puzzlePieces: array{int, int}|null, announced: int}>, hiddenSeconds: int, unlockExact: int, closeAccuracy: float, freeBudgetSeconds: int, maxLevel: int}
     */
    public static function toArray(): array
    {
        $levels = [];
        foreach (array_keys(self::LEVELS) as $level) {
            $levels[] = ['level' => $level, ...self::level($level)];
        }

        return [
            'levels' => $levels,
            'hiddenSeconds' => self::HIDDEN_SECONDS,
            'unlockExact' => self::UNLOCK_EXACT,
            'closeAccuracy' => self::CLOSE_ACCURACY,
            'freeBudgetSeconds' => self::FREE_BUDGET_SECONDS,
            'maxLevel' => self::MAX_LEVEL,
        ];
    }
}
