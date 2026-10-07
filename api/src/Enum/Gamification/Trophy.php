<?php

declare(strict_types=1);

namespace App\Enum\Gamification;

/**
 * The trophies (docs/GAMIFICATION.md, catalogue validated 2026-10-05): only those reachable with
 * today's modules. Stored as its value: add cases, never rename one.
 */
enum Trophy: string
{
    /** A 7-day streak. */
    case OnFire = 'on_fire';
    /** A first Woodpecker cycle completed in time. */
    case Woodpecker = 'woodpecker';
    /** 100 rated fork puzzles solved without help. */
    case GoldenFork = 'golden_fork';
    /** 95 % success over at least 50 repertoire tests within 30 days. */
    case IronMemory = 'iron_memory';
    /** A first exercise finished. */
    case FirstStep = 'first_step';
    /** A 30-day streak. */
    case Unstoppable = 'unstoppable';
    /** A Woodpecker set completed. */
    case SteelWoodpecker = 'steel_woodpecker';
    /** 1 000 puzzles solved (rated, replayed, Woodpecker). */
    case Centurion = 'centurion';
    /** 10 sessions completed. */
    case Conductor = 'conductor';
    /** 50 hours of training. */
    case Marathon = 'marathon';
    /** Streak badges (docs/GAMIFICATION.md, "Badges de série"): 7 and 30 days are OnFire and Unstoppable. */
    case Streak3 = 'streak_3';
    case Streak14 = 'streak_14';
    case Streak50 = 'streak_50';
    case Streak100 = 'streak_100';
    case Streak200 = 'streak_200';
    case Streak300 = 'streak_300';
    case Streak365 = 'streak_365';
    case Streak450 = 'streak_450';
    case Streak500 = 'streak_500';
    case Streak1000 = 'streak_1000';

    /** The threshold, in the unit of its progress. */
    public function goal(): int
    {
        return match ($this) {
            self::OnFire => 7,
            self::Woodpecker, self::FirstStep, self::SteelWoodpecker => 1,
            self::GoldenFork => 100,
            // Tests in the 30 days (and 95 % of them succeeded).
            self::IronMemory => 50,
            self::Unstoppable => 30,
            self::Centurion => 1000,
            self::Conductor => 10,
            // Hours.
            self::Marathon => 50,
            self::Streak3 => 3,
            self::Streak14 => 14,
            self::Streak50 => 50,
            self::Streak100 => 100,
            self::Streak200 => 200,
            self::Streak300 => 300,
            self::Streak365 => 365,
            self::Streak450 => 450,
            self::Streak500 => 500,
            self::Streak1000 => 1000,
        };
    }

    /** Whether it is won by a streak of goal() days. */
    public function isStreak(): bool
    {
        return match ($this) {
            self::OnFire, self::Unstoppable, self::Streak3, self::Streak14, self::Streak50, self::Streak100, self::Streak200,
            self::Streak300, self::Streak365, self::Streak450, self::Streak500, self::Streak1000 => true,
            default => false,
        };
    }

    /**
     * The streak badges, shortest first.
     *
     * @return list<self>
     */
    public static function streaks(): array
    {
        $streaks = array_values(array_filter(self::cases(), static fn (self $trophy): bool => $trophy->isStreak()));
        usort($streaks, static fn (self $a, self $b): int => $a->goal() <=> $b->goal());

        return $streaks;
    }

    /** The badge a streak of exactly $length days wins, null between two milestones. */
    public static function forStreak(int $length): ?self
    {
        foreach (self::streaks() as $trophy) {
            if ($trophy->goal() === $length) {
                return $trophy;
            }
        }

        return null;
    }
}
