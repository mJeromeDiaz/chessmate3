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
        };
    }
}
