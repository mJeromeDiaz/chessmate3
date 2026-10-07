<?php

declare(strict_types=1);

namespace App\Tests\Unit\Coordinates;

use App\Coordinates\Series\CoordinateRules;
use App\Coordinates\Series\SquareDrawer;
use App\Entity\Coordinates\Series;
use App\Entity\Training\Run;
use App\Entity\User;
use App\Enum\Coordinates\Orientation;
use App\Enum\Training\Module;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Symfony\Component\Uid\Uuid;

/**
 * The coordinates series (docs/COORDINATES.md): validation thresholds, the draw, the judging.
 */
final class CoordinatesTest extends TestCase
{
    public function testAValidatingSeriesIsLongEnoughAccurateEnoughAndPlayedToItsEnd(): void
    {
        $min = CoordinateRules::MIN_ANSWERS;
        $atTheRate = (int) ceil($min * CoordinateRules::MIN_SUCCESS_RATE);

        self::assertTrue(CoordinateRules::validates($min, $atTheRate, true), 'exactly at both thresholds');
        self::assertFalse(CoordinateRules::validates($min, $atTheRate - 1, true), 'below the rate');
        self::assertFalse(CoordinateRules::validates($min - 1, $min - 1, true), 'too few answers');
        self::assertFalse(CoordinateRules::validates($min, $min, false), 'stopped');
        self::assertTrue(CoordinateRules::validates(60, 57, true), '95 % of 60');
        self::assertFalse(CoordinateRules::validates(60, 56, true));
        self::assertSame(0, CoordinateRules::SERIES_SECONDS % 60, 'Sessions count in minutes.');
    }

    public function testTheDrawCoversTheBoardWithoutRepeatingASquareAtOnce(): void
    {
        $squares = (new SquareDrawer(new Randomizer(new Mt19937(42))))->draw(5_000);

        self::assertCount(5_000, $squares);
        self::assertCount(64, array_unique($squares), 'Every square comes up.');
        foreach ($squares as $i => $square) {
            self::assertTrue(SquareDrawer::isSquare($square), $square);
            self::assertNotSame($squares[$i - 1] ?? null, $square);
        }
        self::assertSame(['a1', 'h1', 'a2', 'e4', 'h8'], array_map(SquareDrawer::name(...), [0, 7, 8, 28, 63]));
        self::assertFalse(SquareDrawer::isSquare('i1'));
        self::assertFalse(SquareDrawer::isSquare('a9'));
        self::assertFalse(SquareDrawer::isSquare('E4'));
    }

    public function testEachAnswerIsJudgedAgainstItsSquare(): void
    {
        $series = new Series(self::coordinatesRun(), Orientation::Black, ['e4', 'd5']);

        self::assertTrue($series->answer('e4', 1_200));
        self::assertFalse($series->answer('d4', 800));
        self::assertSame([2, 1, 2_000, true], [$series->getAnswerCount(), $series->getSuccessCount(), $series->getAnsweredMs(), $series->isExhausted()]);
        self::assertSame([true, false, null], [$series->isCorrect(0), $series->isCorrect(1), $series->isCorrect(2)]);
        self::assertSame([['square' => 'e4', 'ms' => 1_200], ['square' => 'd4', 'ms' => 800]], $series->getAnswers());

        $this->expectException(\LogicException::class);
        $series->answer('a1', 0);
    }

    private static function coordinatesRun(): Run
    {
        $user = new User();
        $user->setEmail('alice@example.com');

        return new Run($user, Module::Coordinates, 'coordinates_player', Uuid::v7(), CoordinateRules::SERIES_SECONDS, ['orientation' => 'black'], new \DateTimeImmutable('2026-10-07 10:00:00'));
    }
}
