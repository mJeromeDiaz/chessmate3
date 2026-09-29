<?php

declare(strict_types=1);

namespace App\Tests\Unit\Activity;

use App\Activity\Log\LocalDate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LocalDateTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function instants(): iterable
    {
        // 23:30 in Paris is 21:30 UTC in summer (UTC+2) and 22:30 UTC in winter (UTC+1).
        yield 'summer, 23:30 Paris = 21:30 UTC, same day' => ['2026-07-14 21:30:00', 'Europe/Paris', '2026-07-14'];
        yield 'summer, 00:30 Paris = 22:30 UTC the day before' => ['2026-07-14 22:30:00', 'Europe/Paris', '2026-07-15'];
        yield 'winter, 23:30 Paris = 22:30 UTC, same day' => ['2026-01-14 22:30:00', 'Europe/Paris', '2026-01-14'];
        yield 'winter, 00:30 Paris = 23:30 UTC the day before' => ['2026-01-14 23:30:00', 'Europe/Paris', '2026-01-15'];
        // DST start in Paris: 2026-03-29, 02:00 → 03:00.
        yield 'spring forward night' => ['2026-03-28 23:30:00', 'Europe/Paris', '2026-03-29'];
        // DST end in Paris: 2026-10-25 at 01:00 UTC (03:00 → 02:00); that evening Paris is UTC+1,
        // so local midnight is 23:00 UTC (not 22:00 as the day before).
        yield 'fall back day, 23:59:59 local' => ['2026-10-25 22:59:59', 'Europe/Paris', '2026-10-25'];
        yield 'fall back day, local midnight' => ['2026-10-25 23:00:00', 'Europe/Paris', '2026-10-26'];
        yield 'fall back night, repeated 02:30 local (second pass)' => ['2026-10-25 01:30:00', 'Europe/Paris', '2026-10-25'];
        yield 'west of UTC: New York evening' => ['2026-07-15 03:00:00', 'America/New_York', '2026-07-14'];
        yield 'UTC itself' => ['2026-07-14 23:59:59', 'UTC', '2026-07-14'];
    }

    #[DataProvider('instants')]
    public function testTheLocalDayOfAnInstant(string $utc, string $timezone, string $expected): void
    {
        $instant = new \DateTimeImmutable($utc, new \DateTimeZone('UTC'));

        self::assertSame($expected, LocalDate::of($instant, new \DateTimeZone($timezone))->format('Y-m-d'));
    }
}
