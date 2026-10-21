<?php

declare(strict_types=1);

namespace App\Tests\Unit\Calculation;

use App\Calculation\CalculationRules;
use App\Enum\Activity\ExerciseType;
use App\Gamification\Xp\XpRules;
use PHPUnit\Framework\TestCase;

/**
 * The calculation module's numbers (docs/CALCULATION.md): mates' lengths, the Métronome's tempo and
 * mates, the XP of a line.
 */
final class CalculationRulesTest extends TestCase
{
    public function testAMateHasItsHalfMoves(): void
    {
        self::assertSame([1, 3, 5, 7], array_map(CalculationRules::plies(...), [1, 2, 3, 4]));
        self::assertSame('mateIn3', CalculationRules::theme(3));
    }

    public function testTheMetronomeSpeedsUpThenHoldsItsTempo(): void
    {
        self::assertSame([40, 37, 31, 16, 15, 15], array_map(CalculationRules::tempo(...), [0, 1, 3, 8, 9, 20]));
    }

    public function testItsMatesGrowLongerAsLinesComeRight(): void
    {
        self::assertSame(1, min(CalculationRules::streakMates(0)));
        self::assertSame(2, max(CalculationRules::streakMates(2)));
        self::assertSame(4, max(CalculationRules::streakMates(9)));
        self::assertSame(2, min(CalculationRules::streakMates(11)));
    }

    public function testALineEarnsItsXpButTheMetronomesNone(): void
    {
        self::assertSame(4, XpRules::exercise(ExerciseType::Calculation, true, 5_000, 1, ['status' => 'right']));
        self::assertSame(1, XpRules::exercise(ExerciseType::Calculation, false, 5_000, 1, ['status' => 'late']));
        self::assertSame(0, XpRules::exercise(ExerciseType::Calculation, true, 5_000, 1, ['status' => 'right', 'boss' => true]));
    }
}
