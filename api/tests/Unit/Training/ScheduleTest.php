<?php

declare(strict_types=1);

namespace App\Tests\Unit\Training;

use App\Enum\Training\Repetition;
use App\Training\Plan\Schedule;
use PHPUnit\Framework\TestCase;

final class ScheduleTest extends TestCase
{
    private const PARIS = 'Europe/Paris';

    public function testOnDemandHasNoOccurrence(): void
    {
        self::assertNull($this->schedule(Repetition::OnDemand, '18:30', [1, 2, 3])->nextAfter(self::utc('2026-09-28 10:00')));
    }

    public function testDailyOnTheCheckedDaysAtTheLocalTime(): void
    {
        $weekdays = $this->schedule(Repetition::Daily, '18:30', [1, 2, 3, 4, 5]);

        // Monday 12:00 in Paris (UTC+2): this evening.
        self::assertSame('2026-09-28T16:30:00+00:00', $weekdays->nextAfter(self::utc('2026-09-28 10:00'))?->format(\DATE_ATOM));
        // Friday 20:00: past this evening, the weekend is skipped.
        self::assertSame('2026-10-05T16:30:00+00:00', $weekdays->nextAfter(self::utc('2026-10-02 18:00'))?->format(\DATE_ATOM));
        // Strictly after: at the very instant, the next day.
        self::assertSame('2026-09-29T16:30:00+00:00', $weekdays->nextAfter(self::utc('2026-09-28 16:30'))?->format(\DATE_ATOM));
    }

    public function testWeeklyComesBackAWeekLater(): void
    {
        $tuesday = $this->schedule(Repetition::Weekly, '07:00', [2]);

        self::assertSame('2026-09-29T05:00:00+00:00', $tuesday->nextAfter(self::utc('2026-09-28 10:00'))?->format(\DATE_ATOM));
        self::assertSame('2026-10-06T05:00:00+00:00', $tuesday->nextAfter(self::utc('2026-09-29 05:00'))?->format(\DATE_ATOM));
    }

    public function testTheLocalTimeHoldsAcrossDaylightSavingChanges(): void
    {
        $sunday = $this->schedule(Repetition::Weekly, '18:30', [7]);

        // Paris leaves summer time on Sunday 25 October 2026: 18:30 is then UTC+1.
        self::assertSame('2026-10-25T17:30:00+00:00', $sunday->nextAfter(self::utc('2026-10-20 12:00'))?->format(\DATE_ATOM));
        self::assertSame('2026-10-18T16:30:00+00:00', $sunday->nextAfter(self::utc('2026-10-12 12:00'))?->format(\DATE_ATOM));
    }

    public function testATimeSkippedBySpringForwardHappensAfterTheGap(): void
    {
        // Sunday 29 March 2026, 02:00 → 03:00 in Paris: 02:30 does not exist.
        $next = $this->schedule(Repetition::Weekly, '02:30', [7])->nextAfter(self::utc('2026-03-28 12:00'));

        self::assertSame('2026-03-29T01:30:00+00:00', $next?->format(\DATE_ATOM));
    }

    /**
     * @param list<int> $weekdays
     */
    private function schedule(Repetition $repetition, string $time, array $weekdays): Schedule
    {
        return new Schedule($repetition, $time, $weekdays, new \DateTimeZone(self::PARIS));
    }

    private static function utc(string $at): \DateTimeImmutable
    {
        return new \DateTimeImmutable($at, new \DateTimeZone('UTC'));
    }
}
