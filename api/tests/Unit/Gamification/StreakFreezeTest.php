<?php

declare(strict_types=1);

namespace App\Tests\Unit\Gamification;

use App\Gamification\Summary\Streak;
use App\Shop\Freeze\FreezeStock;
use PHPUnit\Framework\TestCase;

/**
 * Frozen days in the daily streak (docs/SHOP.md, "Gel de série"): they keep it without counting,
 * and a gap is covered by the smallest freeze long enough, else the shortest ones together.
 */
final class StreakFreezeTest extends TestCase
{
    public function testAFrozenDayKeepsTheStreakWithoutCounting(): void
    {
        $days = ['2026-10-01', '2026-10-02', '2026-10-05'];
        self::assertSame(['current' => 1, 'best' => 2, 'playedToday' => false], Streak::of($days, '2026-10-06'));
        self::assertSame(['current' => 3, 'best' => 3, 'playedToday' => false], Streak::of($days, '2026-10-06', ['2026-10-03', '2026-10-04']));
        // A frozen yesterday keeps today's streak alive.
        self::assertSame(['current' => 2, 'best' => 2, 'playedToday' => false], Streak::of(['2026-10-01', '2026-10-02'], '2026-10-04', ['2026-10-03']));
        self::assertSame('2026-10-05', Streak::reachedOn($days, 3, ['2026-10-03', '2026-10-04']));
        self::assertNull(Streak::reachedOn($days, 3, ['2026-10-03']), 'one day of the gap uncovered');
        self::assertSame([true, false, false, false, false, false, false], Streak::week(['2026-10-05'], '2026-10-08'));
    }

    public function testTheGapIsTheMissedDaysUntilYesterday(): void
    {
        self::assertSame(['2026-10-06', '2026-10-07'], Streak::gap(['2026-10-05'], [], '2026-10-08'));
        self::assertSame(['2026-10-07'], Streak::gap(['2026-10-05'], ['2026-10-06'], '2026-10-08'));
        self::assertSame([], Streak::gap(['2026-10-07'], [], '2026-10-08'), 'played yesterday');
        self::assertSame([], Streak::gap(['2026-10-08'], [], '2026-10-08'), 'played today');
        self::assertSame([], Streak::gap([], [], '2026-10-08'), 'never played');
    }

    public function testTheSmallestFreezeLongEnoughElseTheShortestTogether(): void
    {
        self::assertSame([1 => 5], FreezeStock::plan([1, 7, 14], 5), 'the week, not the fortnight');
        self::assertSame([0 => 1], FreezeStock::plan([1, 7], 1));
        self::assertSame([0 => 1, 1 => 1], FreezeStock::plan([1, 1], 2), 'two 1-day freezes');
        self::assertSame([0 => 1, 1 => 2, 2 => 1], FreezeStock::plan([1, 2, 2], 4), 'the last one keeps a day');
        self::assertNull(FreezeStock::plan([1, 2], 4));
        self::assertNull(FreezeStock::plan([], 1));
    }
}
