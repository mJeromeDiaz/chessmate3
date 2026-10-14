<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shop;

use App\Enum\Shop\RewardTier;
use App\Shop\Reward\RewardCalculator;
use App\Shop\Reward\Rule;
use PHPUnit\Framework\TestCase;

/**
 * The crowns of a completed session (docs/SHOP.md, § 4 bis): one minute per exercise at most, the
 * tiers of the result screen, the daily cap.
 */
final class RewardCalculatorTest extends TestCase
{
    public function testEachRunPaysItsDurationButOneMinutePerExerciseAtMost(): void
    {
        self::assertSame(['paidSeconds' => 600 + 180 + 0, 'itemCount' => 15 + 3 + 0, 'successCount' => 12 + 3], RewardCalculator::tally([
            // 10 min, 15 exercises: the whole duration.
            ['durationMs' => 600_000, 'itemCount' => 15, 'successCount' => 12],
            // 60 min left running with 3 exercises: 3 minutes.
            ['durationMs' => 3_600_000, 'itemCount' => 3, 'successCount' => 3],
            // Nothing finished: nothing paid.
            ['durationMs' => 300_000, 'itemCount' => 0, 'successCount' => 0],
        ]));
        self::assertSame(90, RewardCalculator::tally([['durationMs' => 90_999, 'itemCount' => 2, 'successCount' => 1]])['paidSeconds'], 'whole seconds');
    }

    public function testTheTiersAreThoseOfTheResultScreen(): void
    {
        self::assertSame(
            [RewardTier::Perfect, RewardTier::Near, RewardTier::Near, RewardTier::Missed, RewardTier::Missed],
            [RewardTier::of(10, 10), RewardTier::of(8, 10), RewardTier::of(99, 100), RewardTier::of(79, 100), RewardTier::of(0, 3)],
        );
    }

    public function testCrownsFollowTheRule(): void
    {
        $rule = new Rule();
        // 20 min at 120 crowns an hour: 40, × 1.5 / × 1 / × 0.5.
        self::assertSame([60, 40, 20], [
            RewardCalculator::crowns(1200, RewardTier::Perfect, $rule),
            RewardCalculator::crowns(1200, RewardTier::Near, $rule),
            RewardCalculator::crowns(1200, RewardTier::Missed, $rule),
        ]);
        // 3 min perfect = 9; 1 min missed = 1 (exactly 1.0); 15 s near = 0.5 → 1 (half up); 14 s → 0.
        self::assertSame([9, 1, 1, 0], [
            RewardCalculator::crowns(180, RewardTier::Perfect, $rule),
            RewardCalculator::crowns(60, RewardTier::Missed, $rule),
            RewardCalculator::crowns(15, RewardTier::Near, $rule),
            RewardCalculator::crowns(14, RewardTier::Near, $rule),
        ]);
        self::assertSame(300, RewardCalculator::crowns(3600, RewardTier::Perfect, new Rule(crownsPerHour: 200)));
    }

    public function testTheDayPaysSixtyMinutesAtMost(): void
    {
        $rule = new Rule();
        self::assertSame([1200, 60, 0, 0], [
            RewardCalculator::capped(1200, 0, $rule),
            RewardCalculator::capped(1200, 3540, $rule),
            RewardCalculator::capped(1200, 3600, $rule),
            RewardCalculator::capped(1200, 4000, $rule),
        ]);
        self::assertSame(600, RewardCalculator::capped(1200, 0, new Rule(dailyMinutes: 10)));
    }
}
