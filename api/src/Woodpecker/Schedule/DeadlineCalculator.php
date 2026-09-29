<?php

declare(strict_types=1);

namespace App\Woodpecker\Schedule;

use App\Woodpecker\Set\SetConfig;

/**
 * Woodpecker calendar (docs/WOODPECKER.md, "Deadlines"). Pure functions: instants in and out are
 * UTC; days are counted in the user's timezone, so a deadline is always the end of a local day
 * (= the next local midnight), whatever the DST changes in between.
 */
final class DeadlineCalculator
{
    /**
     * Length of cycle $number: first × factor^(number − 1), rounded up to whole days, never below
     * the minimum (28, 14, 7, 4, 2, 1, 1 with the defaults).
     */
    public static function cycleDays(SetConfig $config, int $number): int
    {
        $days = $config->firstCycleDays * $config->reductionFactor ** ($number - 1);

        return max(1, $config->minCycleDays, (int) ceil($days - 1e-9));
    }

    /**
     * End of the $days-th local day counting the local day of $start as the first one.
     */
    public static function deadline(\DateTimeImmutable $start, int $days, \DateTimeZone $timezone): \DateTimeImmutable
    {
        return self::localMidnight(self::addDays(self::localDate($start, $timezone), $days), $timezone);
    }

    /**
     * When the next cycle becomes playable: at once without rest, otherwise at the start of the
     * local day that follows $restDays full days of rest.
     */
    public static function restEnd(\DateTimeImmutable $completedAt, int $restDays, \DateTimeZone $timezone): \DateTimeImmutable
    {
        if ($restDays <= 0) {
            return $completedAt;
        }

        return self::localMidnight(self::addDays(self::localDate($completedAt, $timezone), $restDays + 1), $timezone);
    }

    /**
     * Moves an instant by a pause length (exact seconds), then up to the next local midnight, so a
     * deadline stays the end of a local day.
     */
    public static function shift(\DateTimeImmutable $instant, int $seconds, \DateTimeZone $timezone): \DateTimeImmutable
    {
        $moved = $instant->modify(sprintf('%+d seconds', $seconds));
        $local = $moved->setTimezone($timezone);
        if ('00:00:00.000000' === $local->format('H:i:s.u')) {
            return $moved->setTimezone(new \DateTimeZone('UTC'));
        }

        return self::localMidnight(self::addDays($local->format('Y-m-d'), 1), $timezone);
    }

    /**
     * Local calendar days left before $deadline, today included (0 once it has passed).
     */
    public static function daysLeft(\DateTimeImmutable $now, \DateTimeImmutable $deadline, \DateTimeZone $timezone): int
    {
        if ($now >= $deadline) {
            return 0;
        }
        $today = new \DateTimeImmutable(self::localDate($now, $timezone), new \DateTimeZone('UTC'));
        $lastDay = new \DateTimeImmutable(self::localDate($deadline->modify('-1 second'), $timezone), new \DateTimeZone('UTC'));

        return (int) $today->diff($lastDay)->days + 1;
    }

    private static function localDate(\DateTimeImmutable $instant, \DateTimeZone $timezone): string
    {
        return $instant->setTimezone($timezone)->format('Y-m-d');
    }

    private static function addDays(string $date, int $days): string
    {
        return (new \DateTimeImmutable($date, new \DateTimeZone('UTC')))->modify(sprintf('%+d days', $days))->format('Y-m-d');
    }

    /**
     * 00:00 of a local date, as UTC. Where midnight does not exist (a DST jump at midnight), PHP
     * moves to the first valid instant of that day.
     */
    private static function localMidnight(string $date, \DateTimeZone $timezone): \DateTimeImmutable
    {
        return (new \DateTimeImmutable($date.' 00:00:00', $timezone))->setTimezone(new \DateTimeZone('UTC'));
    }
}
