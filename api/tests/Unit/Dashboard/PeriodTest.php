<?php

declare(strict_types=1);

namespace App\Tests\Unit\Dashboard;

use App\Dashboard\Period;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

final class PeriodTest extends TestCase
{
    public function testThePeriodStartsAtLocalMidnightWithTheOffsetOfThatDay(): void
    {
        $user = (new User())->setTimezone('Europe/Paris');

        // Today is 2026-03-30 (summer time, UTC+2); the period starts on 2026-03-24 (winter, UTC+1).
        $period = Period::last(7, $user, new \DateTimeImmutable('2026-03-30 08:00:00', new \DateTimeZone('UTC')));

        self::assertSame(['2026-03-24', '2026-03-30'], [$period->fromDate(), $period->todayDate()]);
        self::assertSame('2026-03-23 23:00:00 UTC', $period->since->format('Y-m-d H:i:s e'));
    }

    public function testTheLengthIsClamped(): void
    {
        $user = new User();
        $now = new \DateTimeImmutable('2026-07-20 10:00:00', new \DateTimeZone('UTC'));

        self::assertSame(Period::MIN_DAYS, Period::last(0, $user, $now)->days);
        self::assertSame(Period::MAX_DAYS, Period::last(10_000, $user, $now)->days);
        self::assertSame('2026-07-20 00:00:00', Period::last(1, $user, $now)->today->format('Y-m-d H:i:s'));
    }
}
