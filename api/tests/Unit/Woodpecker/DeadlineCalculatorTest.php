<?php

declare(strict_types=1);

namespace App\Tests\Unit\Woodpecker;

use App\Woodpecker\Schedule\DeadlineCalculator;
use App\Woodpecker\Set\SetConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DeadlineCalculatorTest extends TestCase
{
    private const PARIS = 'Europe/Paris';

    public function testCyclesShrinkByTheFactorAndStopAtTheMinimum(): void
    {
        self::assertSame([28, 14, 7, 4, 2, 1, 1], $this->lengths(28, 0.5, 1, 7));
        self::assertSame([28, 14, 7, 4, 3, 3, 3], $this->lengths(28, 0.5, 3, 7));
        self::assertSame([10, 10, 10], $this->lengths(10, 1.0, 1, 3));
        self::assertSame([30, 21, 15, 11], $this->lengths(30, 0.7, 1, 4));
        self::assertSame([1, 1], $this->lengths(1, 0.5, 1, 2));
    }

    /**
     * @return iterable<string, array{string, int, string, string}>
     */
    public static function deadlines(): iterable
    {
        yield 'Paris, 28 days across the October DST end' => ['2026-10-01 18:00:00', 28, self::PARIS, '2026-10-28 23:00:00'];
        yield 'Paris, 14 days across the March DST start' => ['2026-03-20 10:00:00', 14, self::PARIS, '2026-04-02 22:00:00'];
        yield 'Paris, started 00:30 local (22:30 UTC the day before)' => ['2026-07-14 22:30:00', 1, self::PARIS, '2026-07-15 22:00:00'];
        yield 'Paris, started 23:30 local in winter' => ['2026-01-14 22:30:00', 1, self::PARIS, '2026-01-14 23:00:00'];
        yield 'New York, 7 days across its November DST end' => ['2026-10-30 12:00:00', 7, 'America/New_York', '2026-11-06 05:00:00'];
        yield 'UTC' => ['2026-05-05 12:34:56', 2, 'UTC', '2026-05-07 00:00:00'];
    }

    #[DataProvider('deadlines')]
    public function testADeadlineIsTheEndOfALocalDay(string $start, int $days, string $timezone, string $expectedUtc): void
    {
        self::assertSame($expectedUtc, $this->utc(DeadlineCalculator::deadline($this->at($start), $days, new \DateTimeZone($timezone))));
    }

    public function testRestStartsTheNextCycleAtALocalMidnight(): void
    {
        $paris = new \DateTimeZone(self::PARIS);

        // Completed 2026-10-24 at 23:30 Paris; one full rest day (the 25th, DST end) → the 26th at
        // 00:00 Paris, which is UTC+1 again.
        self::assertSame('2026-10-25 23:00:00', $this->utc(DeadlineCalculator::restEnd($this->at('2026-10-24 21:30:00'), 1, $paris)));
        self::assertSame('2026-10-24 21:30:00', $this->utc(DeadlineCalculator::restEnd($this->at('2026-10-24 21:30:00'), 0, $paris)));
    }

    public function testAPauseShiftsTheDeadlineThenRoundsUpToALocalMidnight(): void
    {
        $paris = new \DateTimeZone(self::PARIS);

        // Deadline 2026-10-11 00:00 Paris, 1.5 day pause → 2026-10-12 12:00 Paris → end of that day.
        self::assertSame('2026-10-12 22:00:00', $this->utc(DeadlineCalculator::shift($this->at('2026-10-10 22:00:00'), 129_600, $paris)));
        // Exactly 2 days across the DST end: lands on 23:00 local, rounded up to midnight (UTC+1).
        self::assertSame('2026-10-26 23:00:00', $this->utc(DeadlineCalculator::shift($this->at('2026-10-24 22:00:00'), 172_800, $paris)));
        // Landing exactly on a local midnight stays there.
        self::assertSame('2026-10-12 22:00:00', $this->utc(DeadlineCalculator::shift($this->at('2026-10-10 22:00:00'), 172_800, $paris)));
    }

    public function testDaysLeftCountsLocalDaysIncludingToday(): void
    {
        $paris = new \DateTimeZone(self::PARIS);
        $deadline = $this->at('2026-10-28 23:00:00'); // end of 2026-10-28 in Paris

        self::assertSame(1, DeadlineCalculator::daysLeft($this->at('2026-10-28 22:59:00'), $deadline, $paris));
        self::assertSame(9, DeadlineCalculator::daysLeft($this->at('2026-10-20 06:00:00'), $deadline, $paris));
        // 23:30 UTC on the 19th is already the 20th in Paris.
        self::assertSame(9, DeadlineCalculator::daysLeft($this->at('2026-10-19 22:30:00'), $deadline, $paris));
        self::assertSame(0, DeadlineCalculator::daysLeft($deadline, $deadline, $paris));
    }

    /**
     * @return list<int>
     */
    private function lengths(int $first, float $factor, int $min, int $count): array
    {
        $config = new SetConfig(50, 1000, 1500, [], $count, $first, $factor, $min, 0, false);

        return array_map(static fn (int $n): int => DeadlineCalculator::cycleDays($config, $n), range(1, $count));
    }

    private function at(string $utc): \DateTimeImmutable
    {
        return new \DateTimeImmutable($utc, new \DateTimeZone('UTC'));
    }

    private function utc(\DateTimeImmutable $instant): string
    {
        return $instant->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
