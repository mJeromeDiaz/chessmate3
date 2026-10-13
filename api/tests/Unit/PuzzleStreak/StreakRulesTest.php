<?php

declare(strict_types=1);

namespace App\Tests\Unit\PuzzleStreak;

use App\Enum\Activity\ExerciseType;
use App\Enum\PuzzleStreak\Ramp;
use App\Enum\Training\Module;
use App\Gamification\Xp\XpRules;
use App\PuzzleStreak\Selection\Topic;
use App\PuzzleStreak\StreakRules;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The puzzle streak's ramps, topics and XP (docs/PUZZLE_STREAK.md).
 */
final class StreakRulesTest extends TestCase
{
    public function testTheLadderClimbsThirtyPointsAPuzzleThenWidens(): void
    {
        self::assertSame([[550, 650], [450, 750], [200, 1000]], StreakRules::ranges(Ramp::Ladder, 0));
        self::assertSame([850, 950], StreakRules::ranges(Ramp::Ladder, 10)[0]);
        self::assertSame(1500, StreakRules::target(Ramp::Ladder, 30));
        // Capped at LADDER_MAX; a negative count is the start.
        self::assertSame([2950, 3050], StreakRules::ranges(Ramp::Ladder, 500)[0]);
        self::assertSame(StreakRules::ranges(Ramp::Ladder, 0), StreakRules::ranges(Ramp::Ladder, -3));
    }

    public function testTiersOfFivePuzzlesAndTwoHundredPoints(): void
    {
        self::assertSame([[600, 800], [500, 900], [250, 1150]], StreakRules::ranges(Ramp::Tiers, 0));
        self::assertSame([600, 800], StreakRules::ranges(Ramp::Tiers, 4)[0]);
        self::assertSame([800, 1000], StreakRules::ranges(Ramp::Tiers, 5)[0]);
        self::assertSame([2400, 2600], StreakRules::ranges(Ramp::Tiers, 49)[0]);
        // The last tier has no upper step.
        self::assertSame([2600, 3400], StreakRules::ranges(Ramp::Tiers, 50)[0]);
        self::assertSame([2600, 3400], StreakRules::ranges(Ramp::Tiers, 400)[0]);
        self::assertSame(700, StreakRules::target(Ramp::Tiers, 0));
    }

    public function testTopics(): void
    {
        self::assertSame([], Topic::parse('general')->themes);
        self::assertFalse(Topic::parse('general')->isOpening());
        self::assertSame(['rookEndgame'], Topic::parse('theme:rookEndgame')->themes);
        self::assertSame(['mateIn3', 'mateIn4', 'mateIn5'], Topic::parse('theme:mateIn3plus')->themes);

        $opening = Topic::parse('opening:Italian_Game:w');
        self::assertTrue($opening->isOpening());
        self::assertSame(['Italian_Game', 'w'], [$opening->family, $opening->solverColor]);
        self::assertSame('opening:Sicilian_Defense:b', Topic::opening('Sicilian_Defense', 'b')->key);

        foreach (StreakRules::toArray()['topics'] as ['key' => $key, 'category' => $category]) {
            self::assertContains($category, ['endgame', 'mate', 'motif']);
            self::assertSame('theme:'.$key, Topic::parse('theme:'.$key)->key);
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function wrongTopics(): iterable
    {
        yield 'empty' => [''];
        yield 'unknown theme' => ['theme:crushing'];
        yield 'bare theme' => ['fork'];
        yield 'no colour' => ['opening:Italian_Game'];
        yield 'bad colour' => ['opening:Italian_Game:white'];
        yield 'bad family' => ["opening:Queen's_Gambit:w"];
        yield 'too long' => ['opening:'.str_repeat('A', 65).':w'];
    }

    #[DataProvider('wrongTopics')]
    public function testWrongTopicsAreRefused(string $key): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Topic::parse($key);
    }

    public function testXpByTheRatingOfThePuzzleSolved(): void
    {
        self::assertSame(2, XpRules::streakPuzzle(400));
        self::assertSame(2, XpRules::streakPuzzle(799));
        self::assertSame(3, XpRules::streakPuzzle(800));
        self::assertSame(7, XpRules::streakPuzzle(1650));
        self::assertSame(11, XpRules::streakPuzzle(2599));
        self::assertSame(12, XpRules::streakPuzzle(2600));
        self::assertSame(12, XpRules::streakPuzzle(3300));

        self::assertSame(5, XpRules::exercise(ExerciseType::StreakPuzzle, true, 10_000, 1, ['status' => 'solved', 'rating' => 1250]));
        self::assertSame(0, XpRules::exercise(ExerciseType::StreakPuzzle, false, 10_000, 1, ['status' => 'failed', 'rating' => 2500]));
        self::assertSame(Module::PuzzleStreak, XpRules::module(ExerciseType::StreakPuzzle));
    }
}
