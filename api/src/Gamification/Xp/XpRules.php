<?php

declare(strict_types=1);

namespace App\Gamification\Xp;

use App\Enum\Activity\ExerciseType;
use App\Enum\Parcours\Effort;
use App\Enum\Training\Module;
use App\PuzzleRush\RushRules;

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
    /** Blindfold puzzle missed then pardoned on the miss sheet: the top-up to a solved one (uncapped). */
    public const BLINDFOLD_PARDON = self::BLINDFOLD_SOLVED - self::BLINDFOLD_FAILED;
    /** Position evaluation: the engine's category, one away, further or no answer; bonuses. */
    public const EVALUATION_EXACT = 15;
    public const EVALUATION_CLOSE = 6;
    public const EVALUATION_MISS = 1;
    /** The right plan, whatever the category. */
    public const EVALUATION_PLAN = 5;
    /** Exact in less than half the position's time: runs before 2026-10-11 only (no time per position since). */
    public const EVALUATION_FAST = 3;

    /**
     * Puzzle streak: a puzzle solved gains by its rating, STREAK_MIN under STREAK_FROM + STREAK_BAND,
     * one more every STREAK_BAND points, STREAK_MAX at most; a miss gains nothing.
     */
    public const STREAK_MIN = 2;
    public const STREAK_MAX = 12;
    public const STREAK_FROM = 600;
    public const STREAK_BAND = 200;
    /** Bonus: a streak record beaten (docs/PUZZLE_STREAK.md). */
    public const STREAK_RECORD = 25;

    /**
     * Puzzle rush (docs/PUZZLE_RUSH.md): RUSH_PER_POINT per point of its score (a miss wipes out
     * puzzles solved), plus RUSH_ACCURACY_BONUS from RUSH_BONUS_ACCURACY with RUSH_BONUS_MIN_SOLVED
     * puzzles solved at least; one exercise per run, within the daily cap.
     */
    public const RUSH_PER_POINT = 2;
    public const RUSH_ACCURACY_BONUS = 20;
    public const RUSH_BONUS_ACCURACY = 0.9;
    public const RUSH_BONUS_MIN_SOLVED = 10;

    /**
     * Position recall (docs/BLINDFOLD_RECALL.md): an exact rebuild by its level's stage (1 to 5),
     * a close one RECALL_CLOSE, a missed one nothing; RECALL_UNLOCK more for the rebuild that opens
     * the next level (once per level). Within the daily cap.
     */
    public const RECALL_EXACT = [1 => 4, 2 => 6, 3 => 8, 4 => 10, 5 => 12];
    public const RECALL_CLOSE = 2;
    public const RECALL_UNLOCK = 20;

    /** Notation (docs/NOTATION.md): an item right, an item wrong. */
    public const NOTATION_RIGHT = 4;
    public const NOTATION_WRONG = 1;
    /** Vision (docs/VISION.md): a question right, a question wrong. */
    public const VISION_RIGHT = 4;
    public const VISION_WRONG = 1;

    /** Level N to N+1: STEP × N XP; a module's level: MODULE_STEP × N. */
    public const STEP = 250;
    public const MODULE_STEP = 100;

    /** [first level, rank], the highest first. A rank is a key, named by the SPA (gamification.ranks.*). */
    private const RANKS = [
        [30, 'master'],
        [20, 'expert'],
        [15, 'strategist'],
        [10, 'tactician'],
        [5, 'amateur'],
        [1, 'beginner'],
    ];

    /**
     * XP of one exercise, before the daily cap.
     *
     * @param array<mixed> $metadata the exercise's facts (a blindfold puzzle's status, an evaluation's status and bonuses, a streak puzzle's rating, a rush's solved and failed, a rebuild's status, stage and unlock)
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
            ExerciseType::StreakPuzzle, ExerciseType::ParcoursPuzzle => $success ? self::streakPuzzle(\is_int($metadata['rating'] ?? null) ? $metadata['rating'] : 0) : 0,
            ExerciseType::PuzzleRush => self::rush(\is_int($metadata['solved'] ?? null) ? $metadata['solved'] : 0, \is_int($metadata['failed'] ?? null) ? $metadata['failed'] : 0),
            ExerciseType::BlindfoldRecall => self::recall($metadata['status'] ?? null, \is_int($metadata['stage'] ?? null) ? $metadata['stage'] : 1, true === ($metadata['unlocked'] ?? null)),
            ExerciseType::Notation => $success ? self::NOTATION_RIGHT : self::NOTATION_WRONG,
            // Only the boss's reward, when it is beaten (docs/MIND_MAP.md, § 5).
            ExerciseType::Jongleur => 0,
            ExerciseType::Vision => $success ? self::VISION_RIGHT : self::VISION_WRONG,
        };
    }

    /**
     * The bonus of a completed session: a parcours' (docs/PARCOURS.md) by its effort, else
     * SESSION_COMPLETED.
     *
     * @param string|null $parcoursEffort the parcours' effort, null for another session
     */
    public static function sessionBonus(?string $parcoursEffort): int
    {
        return (null === $parcoursEffort ? null : Effort::tryFrom($parcoursEffort))?->bonusXp() ?? self::SESSION_COMPLETED;
    }

    /**
     * XP of a streak puzzle solved, by its rating: 2 under 800, 3 from 800, ..., 12 from 2600.
     */
    public static function streakPuzzle(int $rating): int
    {
        return min(self::STREAK_MAX, self::STREAK_MIN + intdiv(max(0, $rating - self::STREAK_FROM), self::STREAK_BAND));
    }

    /**
     * XP of a puzzle rush: by its score, plus the accuracy bonus; nothing without a puzzle solved.
     */
    public static function rush(int $solved, int $failed): int
    {
        $accuracy = RushRules::accuracy($solved, $failed);
        $bonus = $solved >= self::RUSH_BONUS_MIN_SOLVED && null !== $accuracy && $accuracy >= self::RUSH_BONUS_ACCURACY ? self::RUSH_ACCURACY_BONUS : 0;

        return self::RUSH_PER_POINT * RushRules::score($solved, $failed) + $bonus;
    }

    /**
     * XP of a rebuilt position: by its verdict and its level's stage, plus the unlock bonus.
     */
    public static function recall(mixed $status, int $stage, bool $unlocked): int
    {
        return match ($status) {
            'exact' => self::RECALL_EXACT[$stage] ?? self::RECALL_EXACT[1],
            'close' => self::RECALL_CLOSE,
            default => 0,
        } + ($unlocked ? self::RECALL_UNLOCK : 0);
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
            ExerciseType::StreakPuzzle => Module::PuzzleStreak,
            ExerciseType::ParcoursPuzzle => Module::ParcoursPuzzles,
            ExerciseType::PuzzleRush => Module::PuzzleRush,
            ExerciseType::BlindfoldRecall => Module::BlindfoldRecall,
            ExerciseType::Notation => Module::Notation,
            ExerciseType::Jongleur => Module::Jongleur,
            ExerciseType::Vision => Module::Vision,
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
