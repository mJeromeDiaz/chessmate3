<?php

declare(strict_types=1);

namespace App\Blindfold\Puzzle;

use App\Enum\Blindfold\PuzzleLevel;

/**
 * The rules of blindfold puzzles (docs/BLINDFOLD.md), in one place: change a value here and the
 * API, the front (it reads them from GET /blindfold/puzzles) and the selection follow. Pure.
 *
 * A puzzle is shown for the chosen time, hidden for HIDDEN_SECONDS, then solved from memory on an
 * empty board. After a mistake, the current position is shown again (PEEKS times at most): solved
 * after a peek is "helped"; one mistake more fails it.
 */
final class PuzzleRules
{
    /** Puzzle ratings of each level, bounds included. */
    public const LEVELS = [
        'easy' => [600, 1000],
        'medium' => [1000, 1400],
        'hard' => [1400, 1800],
    ];
    /** Player moves of the solution => the Lichess theme saying so ("4" means 4 or more). */
    public const LENGTHS = [
        2 => 'short',
        3 => 'long',
        4 => 'veryLong',
    ];
    /** How long the position may be shown, in seconds. */
    public const VISIBLE_SECONDS = [5, 10, 15, 20, 30];
    /** How long it stays hidden before the player plays. */
    public const HIDDEN_SECONDS = 3;
    /** Peeks allowed after a mistake. */
    public const PEEKS = 1;
    /** Puzzles already played blindfold are left out of a draw while others remain. */
    public const CANDIDATES = 30;
    public const DRAWS = 3;
    /** A puzzle's time counts for at most this much in the averages (a tab left open). */
    public const ACTIVE_TIME_CAP_MS = 300_000;

    /**
     * @return array{int, int} lowest and highest rating
     */
    public static function ratings(PuzzleLevel $level): array
    {
        return self::LEVELS[$level->value];
    }

    /**
     * The rules as the front reads them.
     *
     * @return array{levels: list<array{key: string, min: int, max: int}>, lengths: list<int>, visibleSeconds: list<int>, hiddenSeconds: int, peeks: int}
     */
    public static function toArray(): array
    {
        $levels = [];
        foreach (self::LEVELS as $key => [$min, $max]) {
            $levels[] = ['key' => $key, 'min' => $min, 'max' => $max];
        }

        return [
            'levels' => $levels,
            'lengths' => array_keys(self::LENGTHS),
            'visibleSeconds' => self::VISIBLE_SECONDS,
            'hiddenSeconds' => self::HIDDEN_SECONDS,
            'peeks' => self::PEEKS,
        ];
    }
}
