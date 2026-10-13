<?php

declare(strict_types=1);

namespace App\PuzzleStreak;

use App\Enum\PuzzleStreak\Ramp;

/**
 * The rules of the puzzle streak (docs/PUZZLE_STREAK.md), in one place: change a value here and the
 * API, the front (it reads them from GET /puzzle-streak) and the selection follow. Pure.
 *
 * A streak starts very easy and gets harder after each puzzle solved; one miss breaks it (no help:
 * a hint or the solution is a miss). Two ramps, chosen at the start: the ladder (a target rating,
 * +LADDER_STEP per puzzle solved) and the tiers (TIER_SIZE puzzles per TIER_WIDTH-point tier).
 * When the range has nothing left, it is widened step by step ({@see self::ranges()}).
 */
final class StreakRules
{
    public const LADDER_START = 600;
    public const LADDER_STEP = 30;
    public const LADDER_MAX = 3000;
    /** Half-widths around the ladder's target: the usual one, then the widenings. */
    public const LADDER_MARGINS = [50, 150, 400];

    public const TIER_START = 600;
    public const TIER_WIDTH = 200;
    public const TIER_SIZE = 5;
    /** The last tier starts here and has no upper step. */
    public const TIER_LAST = 2600;
    public const TIER_LAST_MAX = 3400;
    /** Added on both sides of a tier when it has nothing left. */
    public const TIER_MARGINS = [0, 100, 350];

    /**
     * The topics besides "general" and the openings: key => [category, Lichess theme keys (OR)].
     */
    public const TOPICS = [
        'endgame' => ['endgame', ['endgame']],
        'pawnEndgame' => ['endgame', ['pawnEndgame']],
        'rookEndgame' => ['endgame', ['rookEndgame']],
        'bishopEndgame' => ['endgame', ['bishopEndgame']],
        'knightEndgame' => ['endgame', ['knightEndgame']],
        'queenEndgame' => ['endgame', ['queenEndgame']],
        'queenRookEndgame' => ['endgame', ['queenRookEndgame']],
        'mate' => ['mate', ['mate']],
        'mateIn1' => ['mate', ['mateIn1']],
        'mateIn2' => ['mate', ['mateIn2']],
        'mateIn3plus' => ['mate', ['mateIn3', 'mateIn4', 'mateIn5']],
        'fork' => ['motif', ['fork']],
        'pin' => ['motif', ['pin']],
        'skewer' => ['motif', ['skewer']],
        'discoveredAttack' => ['motif', ['discoveredAttack']],
        'sacrifice' => ['motif', ['sacrifice']],
        'deflection' => ['motif', ['deflection']],
        'attraction' => ['motif', ['attraction']],
        'doubleCheck' => ['motif', ['doubleCheck']],
        'hangingPiece' => ['motif', ['hangingPiece']],
        'trappedPiece' => ['motif', ['trappedPiece']],
    ];

    /** An opening is suggested (and listed) with at least this many puzzles for the side played. */
    public const MIN_OPENING_PUZZLES = 30;

    /** The run of a single streak (the streak page): one hour at most, no clock shown. */
    public const SINGLE_BUDGET_SECONDS = 3600;

    /** A record needs at least this many puzzles (no bonus for one easy puzzle per topic). */
    public const RECORD_MIN = 5;

    /** Puzzles already played in a streak are left out of a draw while others remain. */
    public const CANDIDATES = 30;
    public const DRAWS = 3;
    /** A puzzle's time counts for at most this much in the averages (a tab left open). */
    public const ACTIVE_TIME_CAP_MS = 300_000;

    /**
     * The rating a streak aims at after $solved puzzles solved: the ladder's target, the middle of
     * the tier.
     */
    public static function target(Ramp $ramp, int $solved): int
    {
        [$low, $high] = self::ranges($ramp, $solved)[0];

        return intdiv($low + $high, 2);
    }

    /**
     * The rating ranges to draw the next puzzle from after $solved puzzles solved, bounds
     * included: the usual one first, then wider ones for when it has nothing left.
     *
     * @return non-empty-list<array{int, int}>
     */
    public static function ranges(Ramp $ramp, int $solved): array
    {
        $solved = max(0, $solved);
        if (Ramp::Ladder === $ramp) {
            $target = min(self::LADDER_MAX, self::LADDER_START + self::LADDER_STEP * $solved);

            return array_map(static fn (int $margin): array => [max(0, $target - $margin), $target + $margin], self::LADDER_MARGINS);
        }
        [$low, $high] = self::tier(intdiv($solved, self::TIER_SIZE));

        return array_map(static fn (int $margin): array => [max(0, $low - $margin), $high + $margin], self::TIER_MARGINS);
    }

    /**
     * The rating range of tier $index (0-based).
     *
     * @return array{int, int}
     */
    public static function tier(int $index): array
    {
        $low = self::TIER_START + self::TIER_WIDTH * max(0, $index);

        return $low >= self::TIER_LAST ? [self::TIER_LAST, self::TIER_LAST_MAX] : [$low, $low + self::TIER_WIDTH];
    }

    /**
     * The rules as the front reads them.
     *
     * @return array{ladder: array{start: int, step: int, max: int}, tiers: array{start: int, width: int, size: int, last: int}, topics: list<array{key: string, category: string}>, minOpeningPuzzles: int, singleBudgetSeconds: int, recordMin: int}
     */
    public static function toArray(): array
    {
        $topics = [];
        foreach (self::TOPICS as $key => [$category]) {
            $topics[] = ['key' => $key, 'category' => $category];
        }

        return [
            'ladder' => ['start' => self::LADDER_START, 'step' => self::LADDER_STEP, 'max' => self::LADDER_MAX],
            'tiers' => ['start' => self::TIER_START, 'width' => self::TIER_WIDTH, 'size' => self::TIER_SIZE, 'last' => self::TIER_LAST],
            'topics' => $topics,
            'minOpeningPuzzles' => self::MIN_OPENING_PUZZLES,
            'singleBudgetSeconds' => self::SINGLE_BUDGET_SECONDS,
            'recordMin' => self::RECORD_MIN,
        ];
    }
}
