<?php

declare(strict_types=1);

namespace App\Tests\Unit\PuzzleRush;

use App\Enum\Activity\ExerciseType;
use App\Enum\Training\Module;
use App\Gamification\Xp\XpRules;
use App\PuzzleRush\RushRules;
use PHPUnit\Framework\TestCase;

/**
 * The puzzle rush's score, accuracy, ramp and XP (docs/PUZZLE_RUSH.md).
 */
final class RushRulesTest extends TestCase
{
    public function testAMissWipesOutTwoPuzzlesSolved(): void
    {
        // Fast and sloppy against slower and accurate: the accurate one wins.
        self::assertSame(10, RushRules::score(40, 15));
        self::assertSame(21, RushRules::score(25, 2));
        self::assertSame(0, RushRules::score(3, 5), 'never below 0');
        self::assertSame(0, RushRules::score(0, 0));
    }

    public function testTheAccuracyIsNullWithoutAPuzzlePlayed(): void
    {
        self::assertNull(RushRules::accuracy(0, 0));
        self::assertSame(0.0, RushRules::accuracy(0, 3));
        self::assertSame(0.83, RushRules::accuracy(5, 1));
        self::assertSame(0.91, RushRules::accuracy(10, 1));
        self::assertSame(1.0, RushRules::accuracy(7, 0));
    }

    public function testTheRampStartsBelowTheRatingAndClimbsThirtyPointsAPuzzle(): void
    {
        self::assertSame(1000, RushRules::start(1500.0));
        self::assertSame(1000, RushRules::start(1499.6));
        self::assertSame(RushRules::START_MIN, RushRules::start(800.0), 'never below 600');
        self::assertSame(RushRules::MAX_TARGET, RushRules::start(4000.0));
        self::assertSame(1300, RushRules::target(1000, 10));
        self::assertSame(1000, RushRules::target(1000, -2));
        self::assertSame(RushRules::MAX_TARGET, RushRules::target(2900, 50));
        self::assertSame([[950, 1050], [850, 1150], [600, 1400]], RushRules::ranges(1000));
        self::assertSame([0, 450], RushRules::ranges(50)[2]);
    }

    public function testTheXpFollowsTheScorePlusTheAccuracyBonus(): void
    {
        self::assertSame(0, XpRules::rush(0, 0));
        self::assertSame(0, XpRules::rush(0, 4));
        self::assertSame(0, XpRules::rush(2, 1), 'a miss cancels two puzzles');
        self::assertSame(2 * 3, XpRules::rush(5, 1));
        // 90 % or more with 10 solved at least: the bonus.
        self::assertSame(2 * 9, XpRules::rush(9, 0), 'fewer than 10 solved: no bonus');
        self::assertSame(2 * 10 + XpRules::RUSH_ACCURACY_BONUS, XpRules::rush(10, 0));
        self::assertSame(2 * 8 + XpRules::RUSH_ACCURACY_BONUS, XpRules::rush(10, 1), '91 %');
        self::assertSame(2 * 6, XpRules::rush(10, 2), '83 %: no bonus');
        // Fast and sloppy against slower and accurate.
        self::assertGreaterThan(XpRules::rush(40, 15), XpRules::rush(25, 2));

        self::assertSame(XpRules::rush(10, 1), XpRules::exercise(ExerciseType::PuzzleRush, true, 120_000, 11, ['solved' => 10, 'failed' => 1]));
        self::assertSame(0, XpRules::exercise(ExerciseType::PuzzleRush, false, 120_000, 1, []));
        self::assertSame(Module::PuzzleRush, XpRules::module(ExerciseType::PuzzleRush));
    }
}
