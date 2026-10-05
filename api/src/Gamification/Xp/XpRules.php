<?php

declare(strict_types=1);

namespace App\Gamification\Xp;

use App\Enum\Activity\ExerciseType;
use App\Enum\Training\Module;

/**
 * The XP rules (docs/GAMIFICATION.md, validated 2026-10-05), in one place: change a value here,
 * then run `app:gamification:rebuild`. Pure.
 *
 * @phpstan-type Level array{level: int, xpInLevel: int, xpForNext: int}
 */
final class XpRules
{
    /** XP of exercises in one local day, at most (bonuses are not capped). */
    public const DAILY_EXERCISE_CAP = 500;
    public const SESSION_COMPLETED = 50;
    public const CYCLE_COMPLETED = 100;
    public const SET_COMPLETED = 300;
    /** Free study: one XP a minute, at most this much per run. */
    public const FREE_STUDY_MAX = 60;

    /** Level N to N+1: STEP × N XP; a module's level: MODULE_STEP × N. */
    public const STEP = 250;
    public const MODULE_STEP = 100;

    /** [first level, rank], the highest first. */
    private const RANKS = [
        [30, 'Maître'],
        [20, 'Expert'],
        [15, 'Stratège'],
        [10, 'Tacticien'],
        [5, 'Amateur'],
        [1, 'Débutant'],
    ];

    /**
     * XP of one exercise, before the daily cap.
     */
    public static function exercise(ExerciseType $type, bool $success, int $durationMs): int
    {
        return match ($type) {
            ExerciseType::PuzzleRated => $success ? 10 : 3,
            ExerciseType::PuzzleUnrated => $success ? 4 : 1,
            ExerciseType::WoodpeckerPuzzle => $success ? 8 : 2,
            ExerciseType::RepertoireSegment => $success ? 12 : 4,
            ExerciseType::FreeStudy => min(self::FREE_STUDY_MAX, intdiv(max(0, $durationMs), 60_000)),
        };
    }

    /**
     * The module of an exercise.
     */
    public static function module(ExerciseType $type): Module
    {
        return match ($type) {
            ExerciseType::PuzzleRated, ExerciseType::PuzzleUnrated => Module::Puzzles,
            ExerciseType::WoodpeckerPuzzle => Module::Woodpecker,
            ExerciseType::RepertoireSegment => Module::Repertoire,
            ExerciseType::FreeStudy => Module::Free,
        };
    }

    /**
     * The level reached with $xp, and where it stands in it.
     *
     * @return Level
     */
    public static function level(int $xp, int $step = self::STEP): array
    {
        $level = 1;
        $left = max(0, $xp);
        while ($left >= $step * $level) {
            $left -= $step * $level;
            ++$level;
        }

        return ['level' => $level, 'xpInLevel' => $left, 'xpForNext' => $step * $level];
    }

    public static function rank(int $level): string
    {
        foreach (self::RANKS as [$from, $rank]) {
            if ($level >= $from) {
                return $rank;
            }
        }

        return self::RANKS[\count(self::RANKS) - 1][1];
    }

    /**
     * The next rank and the level it starts at, null at the top.
     *
     * @return array{rank: string, level: int}|null
     */
    public static function nextRank(int $level): ?array
    {
        $next = null;
        foreach (self::RANKS as [$from, $rank]) {
            if ($from > $level) {
                $next = ['rank' => $rank, 'level' => $from];
            }
        }

        return $next;
    }
}
