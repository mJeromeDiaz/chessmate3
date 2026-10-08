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
    /** Coordinates series: a flat gain, for a series of at least COORDINATES_MIN_ANSWERS answers. */
    public const COORDINATES_SERIES = 20;
    public const COORDINATES_MIN_ANSWERS = 10;
    /** Bonus: an orientation of the coordinates validated for the first time (once per orientation). */
    public const COORDINATES_VALIDATED = 100;
    /** Blindfold puzzle: solved, solved after a peek ("helped"), failed. */
    public const BLINDFOLD_SOLVED = 12;
    public const BLINDFOLD_HELPED = 6;
    public const BLINDFOLD_FAILED = 2;
    /** Position evaluation: the engine's category, one away, further or no answer; bonuses. */
    public const EVALUATION_EXACT = 15;
    public const EVALUATION_CLOSE = 6;
    public const EVALUATION_MISS = 1;
    /** The right plan, whatever the category. */
    public const EVALUATION_PLAN = 5;
    /** Exact in less than half the position's time. */
    public const EVALUATION_FAST = 3;

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
     *
     * @param array<mixed> $metadata the exercise's facts (a blindfold puzzle's status, an evaluation's status and bonuses)
     */
    public static function exercise(ExerciseType $type, bool $success, int $durationMs, int $itemCount = 1, array $metadata = []): int
    {
        return match ($type) {
            ExerciseType::PuzzleRated => $success ? 10 : 3,
            ExerciseType::PuzzleUnrated => $success ? 4 : 1,
            ExerciseType::WoodpeckerPuzzle => $success ? 8 : 2,
            ExerciseType::RepertoireSegment => $success ? 12 : 4,
            ExerciseType::FreeStudy => min(self::FREE_STUDY_MAX, intdiv(max(0, $durationMs), 60_000)),
            ExerciseType::CoordinatesSeries => $itemCount >= self::COORDINATES_MIN_ANSWERS ? self::COORDINATES_SERIES : 0,
            ExerciseType::BlindfoldPuzzle => match ($metadata['status'] ?? null) {
                'solved' => self::BLINDFOLD_SOLVED,
                'helped' => self::BLINDFOLD_HELPED,
                default => $success ? self::BLINDFOLD_SOLVED : self::BLINDFOLD_FAILED,
            },
            ExerciseType::PositionEvaluation => match ($metadata['status'] ?? null) {
                'exact' => self::EVALUATION_EXACT,
                'close' => self::EVALUATION_CLOSE,
                default => self::EVALUATION_MISS,
            } + (true === ($metadata['planOk'] ?? null) ? self::EVALUATION_PLAN : 0)
              + (true === ($metadata['fast'] ?? null) ? self::EVALUATION_FAST : 0),
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
            ExerciseType::CoordinatesSeries => Module::Coordinates,
            ExerciseType::BlindfoldPuzzle => Module::Blindfold,
            ExerciseType::PositionEvaluation => Module::Evaluation,
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
