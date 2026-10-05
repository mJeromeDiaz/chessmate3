<?php

declare(strict_types=1);

namespace App\Enum\Gamification;

use App\Enum\Training\Module;

/**
 * The weekly quest templates (docs/GAMIFICATION.md, validated 2026-10-05). Stored as its value:
 * add cases, never rename one.
 */
enum QuestTemplate: string
{
    /** Solve N rated puzzles. */
    case RatedPuzzles = 'rated_puzzles';
    /** Solve N rated puzzles of the weakest theme without help. */
    case WeakTheme = 'weak_theme';
    /** Complete N sessions. */
    case Sessions = 'sessions';
    /** Play N Woodpecker puzzles. */
    case Woodpecker = 'woodpecker';
    /** Succeed N repertoire segments. */
    case Repertoire = 'repertoire';
    /** Train N days this week. */
    case ActiveDays = 'active_days';

    public function reward(): int
    {
        return self::WeakTheme === $this ? 200 : 150;
    }

    /**
     * The bounds of N.
     *
     * @return array{int, int}
     */
    public function bounds(): array
    {
        return match ($this) {
            self::RatedPuzzles => [15, 150],
            self::WeakTheme => [5, 20],
            self::Sessions => [2, 5],
            self::Woodpecker => [20, 200],
            self::Repertoire => [10, 60],
            self::ActiveDays => [3, 6],
        };
    }

    /** N rounded to 5 (large counts), or to 1. */
    public function roundsToFive(): bool
    {
        return \in_array($this, [self::RatedPuzzles, self::Woodpecker, self::Repertoire], true);
    }

    /** The module the quest belongs to (its professor), null for one across modules. */
    public function module(): ?Module
    {
        return match ($this) {
            self::RatedPuzzles, self::WeakTheme => Module::Puzzles,
            self::Woodpecker => Module::Woodpecker,
            self::Repertoire => Module::Repertoire,
            self::Sessions, self::ActiveDays => null,
        };
    }
}
