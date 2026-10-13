<?php

declare(strict_types=1);

namespace App\Enum\PuzzleStreak;

/**
 * How a puzzle streak run is played (docs/PUZZLE_STREAK.md). Stored as its value: add cases, never
 * rename one.
 */
enum PlayMode: string
{
    /** The streak page: one streak, no clock shown; the run ends with the streak. */
    case Single = 'single';
    /** A session step: streaks one after the other until the time is up; the best one counts. */
    case Timed = 'timed';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $mode): string => $mode->value, self::cases());
    }
}
