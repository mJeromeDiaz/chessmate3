<?php

declare(strict_types=1);

namespace App\Tests\Unit\Endgame;

use App\Endgame\EndgameRules;
use App\Enum\Activity\ExerciseType;
use App\Gamification\Xp\XpRules;
use PHPUnit\Framework\TestCase;

final class EndgameRulesTest extends TestCase
{
    public function testPerfectPlayPlusHalfAtLeastFourMoves(): void
    {
        // Mate in 13 plies: 7 moves, + 4.
        self::assertSame(11, EndgameRules::budgetMoves(13));
        // Fou + Cavalier, mate in 59 plies: 30 moves, + 15.
        self::assertSame(45, EndgameRules::budgetMoves(59));
        self::assertSame(45, EndgameRules::budgetMoves(-59));
        self::assertSame(EndgameRules::DEFAULT_BUDGET_MOVES, EndgameRules::budgetMoves(null));
    }

    public function testTheRoiDesCendresClockShortensDownToThirtySeconds(): void
    {
        self::assertSame([90, 82, 74, 66, 58, 50, 42, 34, 30, 30], array_map(EndgameRules::ashesClock(...), range(1, 10)));
        self::assertSame([1, 1, 1, 2, 2, 2, 3, 3, 3, 3], array_map(EndgameRules::ashesTier(...), range(1, 10)));
    }

    public function testTheRoiDesCendresMarginsAreTight(): void
    {
        // Mate in 15 plies: 8 moves; +25 % gives 10, then +10 % at least 1 gives 9.
        self::assertSame(10, EndgameRules::ashesBudget(15, 1));
        self::assertSame(10, EndgameRules::ashesBudget(15, 5));
        self::assertSame(9, EndgameRules::ashesBudget(15, 6));
        // Mate in 7 plies: 4 moves; at least +2, then at least +1.
        self::assertSame(6, EndgameRules::ashesBudget(7, 1));
        self::assertSame(5, EndgameRules::ashesBudget(-7, 10));
        // 20 moves: 25 exactly, 22 exactly (no float rounding up).
        self::assertSame(25, EndgameRules::ashesBudget(39, 1));
        self::assertSame(22, EndgameRules::ashesBudget(39, 6));
        self::assertSame(EndgameRules::DEFAULT_BUDGET_MOVES, EndgameRules::ashesBudget(null, 6));
    }

    public function testRunsAndTheTopOfTheSlider(): void
    {
        self::assertSame([120, 300, 600, 900], EndgameRules::budgets());
        self::assertFalse(EndgameRules::allows(60));
        self::assertTrue(EndgameRules::isPerfect(2600));
        self::assertFalse(EndgameRules::isPerfect(2550));
    }

    public function testTheEloProposedIsThePuzzleRatingRoundedBelowPerfect(): void
    {
        self::assertSame(1500, EndgameRules::proposedElo(1500.0));
        self::assertSame(1700, EndgameRules::proposedElo(1650.2));
        self::assertSame(800, EndgameRules::proposedElo(420.0));
        self::assertSame(2500, EndgameRules::proposedElo(2890.0), '« Parfait » is chosen, never proposed.');
    }

    public function testXpByTheOpponentsStrength(): void
    {
        self::assertSame(15, XpRules::exercise(ExerciseType::Endgame, true, 60_000, 1, ['opponentElo' => 1200]));
        self::assertSame(25, XpRules::exercise(ExerciseType::Endgame, true, 60_000, 1, ['opponentElo' => 1400]));
        self::assertSame(25, XpRules::exercise(ExerciseType::Endgame, true, 60_000, 1, ['opponentElo' => 2550]));
        self::assertSame(35, XpRules::exercise(ExerciseType::Endgame, true, 60_000, 1, ['opponentElo' => null]));
        self::assertSame(2, XpRules::exercise(ExerciseType::Endgame, false, 60_000, 1, ['opponentElo' => null]));
    }
}
