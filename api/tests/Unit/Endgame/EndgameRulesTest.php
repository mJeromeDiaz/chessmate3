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

    public function testRunsAndTheTopOfTheSlider(): void
    {
        self::assertSame([120, 300, 600, 900], EndgameRules::budgets());
        self::assertFalse(EndgameRules::allows(60));
        self::assertTrue(EndgameRules::isPerfect(2600));
        self::assertFalse(EndgameRules::isPerfect(2550));
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
