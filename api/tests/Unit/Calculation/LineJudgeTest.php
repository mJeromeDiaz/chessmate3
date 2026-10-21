<?php

declare(strict_types=1);

namespace App\Tests\Unit\Calculation;

use App\Calculation\Drawing\LinePicker;
use App\Calculation\Drawing\LineSpec;
use App\Enum\Calculation\Ending;
use App\Calculation\Judge\IllegalLineException;
use App\Calculation\Judge\LineJudge;
use App\Chess\Rules;
use App\DataFixtures\Puzzle\SamplePuzzles;
use App\Entity\Catalog\Puzzle;
use PHPUnit\Framework\TestCase;

/**
 * A line played at once (docs/CALCULATION.md, § 3) on a sample mate in 2: GlnnF, after c5c6 black
 * mates by Rxh4+ gxh4 Qg2#.
 */
final class LineJudgeTest extends TestCase
{
    private const SOLUTION = ['h8h4', 'g3h4', 'd5g2'];

    public function testTheSolutionDefenceIncludedIsRight(): void
    {
        self::assertSame(['right' => true, 'mistakeAt' => null], LineJudge::judge(self::fen(), self::SOLUTION, self::SOLUTION));
    }

    public function testAnotherDefenceOrALineCutShortIsWrong(): void
    {
        $other = self::otherDefence();
        self::assertSame(['right' => false, 'mistakeAt' => 1], LineJudge::judge(self::fen(), self::SOLUTION, ['h8h4', $other]));
        self::assertSame(['right' => false, 'mistakeAt' => 1], LineJudge::judge(self::fen(), self::SOLUTION, ['h8h4']));
        self::assertSame(['right' => false, 'mistakeAt' => 0], LineJudge::judge(self::fen(), self::SOLUTION, [self::otherFirstMove()]));
    }

    public function testALineWithoutMateIsRightPlayedToItsEnd(): void
    {
        // Judged as a gain: the same three half-moves, the last one not needed to mate.
        self::assertSame(['right' => true, 'mistakeAt' => null], LineJudge::judge(self::fen(), ['h8h4', 'g3h4', 'd5d4'], ['h8h4', 'g3h4', 'd5d4']));
        self::assertSame(['right' => false, 'mistakeAt' => 2], LineJudge::judge(self::fen(), ['h8h4', 'g3h4', 'd5d4'], ['h8h4', 'g3h4']));
        self::assertSame(['right' => true, 'mistakeAt' => null], LineJudge::judge(self::fen(), ['h8h4', 'g3h4', 'd5d4'], self::SOLUTION), 'A mate is always right.');
    }

    public function testAnIllegalHalfMoveIsRefused(): void
    {
        $this->expectException(IllegalLineException::class);
        LineJudge::judge(self::fen(), self::SOLUTION, ['h8h4', 'a1a8']);
    }

    public function testAPuzzleMakesALineOfItsMateOnly(): void
    {
        $puzzle = self::puzzle();
        $line = LinePicker::line($puzzle, LineSpec::mate(2));
        self::assertNotNull($line);
        self::assertSame([2, Ending::Mate, self::SOLUTION, self::fen()], [$line->moves, $line->ending, $line->expected, $line->fen]);
        self::assertNotNull(LinePicker::line($puzzle, LineSpec::mate(2, true)), 'Rxh4 is a capture on the way.');
        self::assertNull(LinePicker::line($puzzle, LineSpec::mate(1)), 'Another length.');
        self::assertNull(LinePicker::line($puzzle, LineSpec::mate(3)));
        self::assertNull(LinePicker::line($puzzle, new LineSpec([2], Ending::Material)), 'It mates.');
        self::assertNotNull(LinePicker::line($puzzle, new LineSpec([2, 3])), 'Any ending.');
    }

    private static function puzzle(): Puzzle
    {
        foreach (SamplePuzzles::create() as $puzzle) {
            if ('GlnnF' === $puzzle->getLichessId()) {
                return $puzzle;
            }
        }
        self::fail('No sample puzzle GlnnF.');
    }

    /** The position once c5c6 is played: black to mate. */
    private static function fen(): string
    {
        $rules = Rules::fromFen(self::puzzle()->getFen());
        self::assertNotNull($rules->playUci('c5c6'));

        return $rules->fen();
    }

    /** A legal reply to Rxh4+ other than gxh4. */
    private static function otherDefence(): string
    {
        $rules = Rules::fromFen(self::fen());
        self::assertNotNull($rules->playUci('h8h4'));
        foreach ($rules->legalMoves() as $move) {
            if ('g3h4' !== Rules::uci($move)) {
                return Rules::uci($move);
            }
        }
        self::fail('Only one defence.');
    }

    /** A legal first move that neither is the solution's nor mates. */
    private static function otherFirstMove(): string
    {
        foreach (Rules::fromFen(self::fen())->legalMoves() as $move) {
            $uci = Rules::uci($move);
            $after = Rules::fromFen(self::fen());
            $after->playUci($uci);
            if ('h8h4' !== $uci && !$after->inCheckmate()) {
                return $uci;
            }
        }
        self::fail('No other first move.');
    }
}
