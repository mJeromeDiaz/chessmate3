<?php

declare(strict_types=1);

namespace App\Gamification\Summary;

use App\Enum\Gamification\Trophy;

/**
 * Streaks of active local days (docs/GAMIFICATION.md): a day counts with at least one exercise.
 * The current streak is still alive when the last active day is yesterday (today is not over).
 * Pure.
 */
final class Streak
{
    /**
     * @param list<string> $days active local days (Y-m-d), oldest first, distinct
     *
     * @return array{current: int, best: int, playedToday: bool}
     */
    public static function of(array $days, string $today): array
    {
        $best = 0;
        $run = 0;
        $previous = null;
        foreach ($days as $day) {
            $run = null !== $previous && self::next($previous) === $day ? $run + 1 : 1;
            $best = max($best, $run);
            $previous = $day;
        }
        $last = $days[\count($days) - 1] ?? null;
        $alive = null !== $last && ($last === $today || self::next($last) === $today);

        return ['current' => $alive ? $run : 0, 'best' => $best, 'playedToday' => $last === $today];
    }

    /**
     * The day a streak of $length days was first reached, null if never.
     *
     * @param list<string> $days active local days (Y-m-d), oldest first, distinct
     */
    public static function reachedOn(array $days, int $length): ?string
    {
        $run = 0;
        $previous = null;
        foreach ($days as $day) {
            $run = null !== $previous && self::next($previous) === $day ? $run + 1 : 1;
            if ($run >= $length) {
                return $day;
            }
            $previous = $day;
        }

        return null;
    }

    /**
     * The active days of the week (Monday to Sunday) holding $today.
     *
     * @param list<string> $days active local days (Y-m-d)
     *
     * @return list<bool> 7 days, Monday first
     */
    public static function week(array $days, string $today): array
    {
        $monday = (new \DateTimeImmutable($today.' 00:00:00', new \DateTimeZone('UTC')))->modify('monday this week');
        $active = array_flip($days);
        $week = [];
        for ($i = 0; $i < 7; ++$i) {
            $week[] = isset($active[$monday->modify(\sprintf('+%d days', $i))->format('Y-m-d')]);
        }

        return $week;
    }

    /** The next streak badge's length after a streak of $length days, null past the last one. */
    public static function nextMilestone(int $length): ?int
    {
        foreach (Trophy::streaks() as $trophy) {
            if ($trophy->goal() > $length) {
                return $trophy->goal();
            }
        }

        return null;
    }

    private static function next(string $day): string
    {
        return (new \DateTimeImmutable($day.' 00:00:00', new \DateTimeZone('UTC')))->modify('+1 day')->format('Y-m-d');
    }
}
