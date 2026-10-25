<?php

declare(strict_types=1);

namespace App\Gamification\Summary;

use App\Enum\Gamification\Trophy;

/**
 * Streaks of active local days (docs/GAMIFICATION.md): a day counts with at least one exercise.
 * The current streak is still alive when the last active day is yesterday (today is not over).
 * A frozen day (docs/SHOP.md, "Gel de série") keeps the streak going without counting in it.
 * Pure.
 */
final class Streak
{
    /**
     * @param list<string> $days   active local days (Y-m-d), oldest first, distinct
     * @param list<string> $frozen frozen local days (Y-m-d), none of them active
     *
     * @return array{current: int, best: int, playedToday: bool}
     */
    public static function of(array $days, string $today, array $frozen = []): array
    {
        $best = 0;
        $run = 0;
        $previous = null;
        foreach (self::timeline($days, $frozen) as $day => $active) {
            if (null === $previous || self::next($previous) !== $day) {
                $run = 0;
            }
            if ($active) {
                ++$run;
            }
            $best = max($best, $run);
            $previous = $day;
        }
        $alive = null !== $previous && ($previous === $today || self::next($previous) === $today);

        return ['current' => $alive ? $run : 0, 'best' => $best, 'playedToday' => \in_array($today, $days, true)];
    }

    /**
     * The day a streak of $length days was first reached (an active day), null if never.
     *
     * @param list<string> $days   active local days (Y-m-d), oldest first, distinct
     * @param list<string> $frozen frozen local days (Y-m-d)
     */
    public static function reachedOn(array $days, int $length, array $frozen = []): ?string
    {
        $run = 0;
        $previous = null;
        foreach (self::timeline($days, $frozen) as $day => $active) {
            if (null === $previous || self::next($previous) !== $day) {
                $run = 0;
            }
            if ($active) {
                ++$run;
                if ($run >= $length) {
                    return $day;
                }
            }
            $previous = $day;
        }

        return null;
    }

    /**
     * The missed days between the last active or frozen day and yesterday (the gap a freeze would
     * cover), oldest first; none when the streak is alive or there was never a streak.
     *
     * @param list<string> $days   active local days (Y-m-d), oldest first
     * @param list<string> $frozen frozen local days (Y-m-d)
     *
     * @return list<string>
     */
    public static function gap(array $days, array $frozen, string $today): array
    {
        $timeline = self::timeline($days, $frozen);
        if ([] === $timeline) {
            return [];
        }
        $last = array_key_last($timeline);
        $missed = [];
        for ($day = self::next((string) $last); $day < $today; $day = self::next($day)) {
            $missed[] = $day;
        }

        return $missed;
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

    /**
     * The local month holding $today, day by day (docs/DASHBOARD.md, the streak's sheet): `played`
     * (active), `frozen`, `missed` (neither, from the first active day on), `none` (before it),
     * `future` (after today, and today while nothing is played yet).
     *
     * @param list<string> $days   active local days (Y-m-d)
     * @param list<string> $frozen frozen local days (Y-m-d)
     *
     * @return array{first: string, days: list<string>}
     */
    public static function month(array $days, array $frozen, string $today): array
    {
        $first = substr($today, 0, 8).'01';
        $length = (int) (new \DateTimeImmutable($first.' 00:00:00', new \DateTimeZone('UTC')))->format('t');
        $active = array_flip($days);
        $kept = array_flip($frozen);
        $start = $days[0] ?? null;
        $states = [];
        for ($i = 1; $i <= $length; ++$i) {
            $day = substr($first, 0, 8).\sprintf('%02d', $i);
            $states[] = match (true) {
                isset($active[$day]) => 'played',
                isset($kept[$day]) => 'frozen',
                $day >= $today => 'future',
                null !== $start && $day > $start => 'missed',
                default => 'none',
            };
        }

        return ['first' => $first, 'days' => $states];
    }

    /**
     * Active and frozen days in order: day => active (true) or frozen (false).
     *
     * @param list<string> $days
     * @param list<string> $frozen
     *
     * @return array<string, bool>
     */
    private static function timeline(array $days, array $frozen): array
    {
        $timeline = array_fill_keys($frozen, false);
        foreach ($days as $day) {
            $timeline[$day] = true;
        }
        ksort($timeline, \SORT_STRING);

        return $timeline;
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
