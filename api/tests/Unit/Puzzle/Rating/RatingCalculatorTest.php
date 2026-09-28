<?php

declare(strict_types=1);

namespace App\Tests\Unit\Puzzle\Rating;

use App\Puzzle\Rating\RatingCalculator;
use App\Puzzle\Rating\RatingState;
use PHPUnit\Framework\TestCase;

final class RatingCalculatorTest extends TestCase
{
    private RatingCalculator $calculator;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->calculator = new RatingCalculator();
        $this->now = new \DateTimeImmutable('2026-09-28 12:00:00');
    }

    public function testANewPlayerMovesALotAndBecomesMoreCertain(): void
    {
        $after = $this->calculator->afterAttempt(RatingCalculator::initial(), null, $this->now, 1500, 80, true);

        self::assertGreaterThan(1600, $after->rating);
        self::assertLessThan(350, $after->deviation);
    }

    public function testSolvingRaisesAndFailingLowers(): void
    {
        $player = new RatingState(1500, 80, 0.06);

        self::assertGreaterThan(1500, $this->calculator->afterAttempt($player, $this->now, $this->now, 1500, 80, true)->rating);
        self::assertLessThan(1500, $this->calculator->afterAttempt($player, $this->now, $this->now, 1500, 80, false)->rating);
    }

    public function testDeviationNeverDropsBelowTheFloor(): void
    {
        $state = new RatingState(1500, 46, 0.03);
        for ($i = 0; $i < 50; ++$i) {
            $state = $this->calculator->afterAttempt($state, $this->now, $this->now, (int) round($state->rating), 60, 0 === $i % 2);
        }

        self::assertSame(RatingCalculator::MIN_DEVIATION, $state->deviation);
    }

    public function testInactivityWidensTheDeviationBeforeRating(): void
    {
        $player = new RatingState(1500, 60, 0.06);

        $fresh = $this->calculator->afterAttempt($player, $this->now, $this->now, 1500, 80, true);
        $afterAYear = $this->calculator->afterAttempt($player, $this->now->modify('-365 days'), $this->now, 1500, 80, true);

        self::assertGreaterThan($fresh->rating, $afterAYear->rating);
        self::assertGreaterThan($fresh->deviation, $afterAYear->deviation);
        self::assertLessThanOrEqual(RatingCalculator::MAX_DEVIATION, $afterAYear->deviation);
    }

    public function testAnUncertainPuzzleMovesThePlayerLess(): void
    {
        $player = new RatingState(1500, 100, 0.06);

        $settled = $this->calculator->afterAttempt($player, $this->now, $this->now, 1500, 60, true);
        $uncertain = $this->calculator->afterAttempt($player, $this->now, $this->now, 1500, 300, true);

        self::assertGreaterThan($uncertain->rating, $settled->rating);
    }

    public function testLichessSeedKeepsTheRatingButNotAnOverconfidentDeviation(): void
    {
        $seed = RatingCalculator::fromLichess(2034, 62);

        self::assertSame(2034.0, $seed->rating);
        self::assertSame(RatingCalculator::MIN_IMPORTED_DEVIATION, $seed->deviation);
        self::assertSame(RatingCalculator::MAX_DEVIATION, RatingCalculator::fromLichess(1500, 500)->deviation);
    }
}
