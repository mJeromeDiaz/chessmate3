<?php

declare(strict_types=1);

namespace App\Activity\Log;

/**
 * The calendar day of an instant in a timezone (DST-aware: PHP applies the offset in force at that
 * instant). E.g. 2026-07-14 22:30 UTC is 2026-07-15 in Paris (UTC+2), 2026-01-14 22:30 UTC is
 * still 2026-01-14 there (UTC+1).
 */
final class LocalDate
{
    public static function of(\DateTimeImmutable $instant, \DateTimeZone $timezone): \DateTimeImmutable
    {
        return new \DateTimeImmutable($instant->setTimezone($timezone)->format('Y-m-d'), new \DateTimeZone('UTC'));
    }
}
