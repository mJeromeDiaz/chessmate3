<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shop;

use App\Enum\Shop\WheelGift;
use App\Enum\Shop\WheelPrize;
use App\Shop\Wheel\Odds;
use PHPUnit\Framework\TestCase;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

final class WheelOddsTest extends TestCase
{
    public function testThePrizesComeOutByTheirWeight(): void
    {
        $randomizer = new Randomizer(new Xoshiro256StarStar(str_repeat('w', 32)));
        $odds = new Odds();
        $counts = array_fill_keys(array_map(static fn (WheelPrize $prize): string => $prize->value, WheelPrize::cases()), 0);
        for ($i = 0; $i < 10_000; ++$i) {
            ++$counts[$odds->draw($randomizer)->value];
        }

        // 75 %, 12 %, 1 %, 7 %, 5 %, give or take.
        self::assertEqualsWithDelta(7_500, $counts['c5'], 200);
        self::assertEqualsWithDelta(1_200, $counts['c30'], 120);
        self::assertEqualsWithDelta(100, $counts['c300'], 40);
        self::assertEqualsWithDelta(700, $counts['gift'], 100);
        self::assertEqualsWithDelta(500, $counts['lose'], 90);
        self::assertSame([5, 30, 300, 0, 0], array_map(static fn (WheelPrize $prize): int => $prize->crowns(), WheelPrize::cases()));
    }

    public function testAPrizeWeighingNothingNeverComesOut(): void
    {
        $randomizer = new Randomizer(new Xoshiro256StarStar(str_repeat('x', 32)));
        $only = new Odds(0, 0, 3, 0, 0, 100);
        for ($i = 0; $i < 200; ++$i) {
            self::assertSame(WheelPrize::Crystals300, $only->draw($randomizer));
            self::assertSame(WheelGift::Freeze, $only->gift($randomizer));
        }
        self::assertSame(WheelGift::Costume, (new Odds(freezePercent: 0))->gift($randomizer));
    }

    public function testOneWeightAtLeast(): void
    {
        self::assertTrue((new Odds())->isValid());
        self::assertTrue((new Odds(0, 0, 0, 0, 1))->isValid());
        self::assertFalse((new Odds(0, 0, 0, 0, 0))->isValid());
        self::assertFalse((new Odds(freezePercent: 101))->isValid());
    }
}
