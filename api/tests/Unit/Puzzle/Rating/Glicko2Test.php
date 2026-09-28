<?php

declare(strict_types=1);

namespace App\Tests\Unit\Puzzle\Rating;

use App\Puzzle\Rating\GameResult;
use App\Puzzle\Rating\Glicko2;
use App\Puzzle\Rating\RatingState;
use PHPUnit\Framework\TestCase;

final class Glicko2Test extends TestCase
{
    /**
     * The worked example of Mark Glickman's "Example of the Glicko-2 system" (glicko2.pdf): a
     * 1500/200/0.06 player beats a 1400/30 player, loses to 1550/100 and to 1700/300, with τ = 0.5.
     */
    public function testReproducesGlickmansExample(): void
    {
        $result = (new Glicko2(0.5))->rate(new RatingState(1500, 200, 0.06), [
            new GameResult(1400, 30, GameResult::WIN),
            new GameResult(1550, 100, GameResult::LOSS),
            new GameResult(1700, 300, GameResult::LOSS),
        ]);

        self::assertEqualsWithDelta(1464.06, $result->rating, 0.01);
        self::assertEqualsWithDelta(151.52, $result->deviation, 0.01);
        self::assertEqualsWithDelta(0.05999, $result->volatility, 0.00001);
    }

    public function testAPeriodWithoutGamesOnlyIncreasesTheDeviation(): void
    {
        $result = (new Glicko2(0.5))->rate(new RatingState(1500, 200, 0.06), []);

        self::assertSame(1500.0, $result->rating);
        self::assertEqualsWithDelta(sqrt((200 / 173.7178) ** 2 + 0.06 ** 2) * 173.7178, $result->deviation, 1e-9);
        self::assertSame(0.06, $result->volatility);
    }

    public function testWinningAgainstAStrongerOpponentGainsMoreThanAgainstAWeakerOne(): void
    {
        $glicko = new Glicko2(0.5);
        $player = new RatingState(1500, 100, 0.06);

        $vsStronger = $glicko->rate($player, [new GameResult(1800, 80, GameResult::WIN)]);
        $vsWeaker = $glicko->rate($player, [new GameResult(1200, 80, GameResult::WIN)]);

        self::assertGreaterThan($vsWeaker->rating - 1500, $vsStronger->rating - 1500);
        self::assertGreaterThan(1500, $vsWeaker->rating);
        self::assertLessThan(100, $vsStronger->deviation);
    }

    public function testExpectedScoreIsSymmetricAroundEqualRatings(): void
    {
        $glicko = new Glicko2(0.5);

        self::assertEqualsWithDelta(0.5, $glicko->expectedScore(1500, 1500, 80), 1e-12);
        self::assertEqualsWithDelta(1.0, $glicko->expectedScore(1600, 1500, 80) + $glicko->expectedScore(1400, 1500, 80), 1e-12);
        // About 64% at +100: the target of the adaptive selection (docs/PUZZLES.md).
        self::assertEqualsWithDelta(0.64, $glicko->expectedScore(1600, 1500, 80), 0.01);
    }
}
