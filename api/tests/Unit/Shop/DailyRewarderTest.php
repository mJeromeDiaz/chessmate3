<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shop;

use App\Enum\Gamification\Trophy;
use App\Shop\Reward\DailyRewarder;
use PHPUnit\Framework\TestCase;

/**
 * A streak badge's bonus in the daily chest (docs/SHOP.md, § 4 ter, validated on 2026-10-21): 10
 * crystals per day, 1,000 at most.
 */
final class DailyRewarderTest extends TestCase
{
    public function testTenCrystalsPerDayCappedAtAThousand(): void
    {
        $bonuses = [];
        foreach (Trophy::streaks() as $badge) {
            $bonuses[$badge->goal()] = DailyRewarder::badgeCrowns($badge->goal());
        }
        self::assertSame([
            3 => 30, 7 => 70, 14 => 140, 30 => 300, 50 => 500, 100 => 1000,
            200 => 1000, 300 => 1000, 365 => 1000, 450 => 1000, 500 => 1000, 1000 => 1000,
        ], $bonuses);
        self::assertSame(0, DailyRewarder::badgeCrowns(0));
    }
}
