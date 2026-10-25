<?php

declare(strict_types=1);

namespace App\Tests\Unit\Calculation;

use App\Calculation\Drawing\LinePicker;
use App\Calculation\Drawing\LineSpec;
use App\Enum\Calculation\Ending;
use App\Calculation\Judge\IllegalLineException;
use App\Calculation\Judge\LineJudge;
use App\Calculation\Judge\MateSearch;
use App\Calculation\Judge\SearchBudgetExceededException;
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
    /** White mates in 2: Qg4+ Kf8 (or Kh8) Qg7#, the f6 pawn guarding g7. */
    private const FLIGHTS = '4r1k1/5p1p/5P2/8/8/8/8/3Q2K1 w - - 0 1';

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

    public function testAMateTakesAnyDefence(): void
    {
        // Qg4+: the king flees to f8 or h8, Qg7# either way (chosen 2026-10-25).
        $solution = ['d1g4', 'g8f8', 'g4g7'];
        self::assertSame(['right' => true, 'mistakeAt' => null], LineJudge::judge(self::FLIGHTS, $solution, ['d1g4', 'g8h8', 'g4g7'], true));
        self::assertSame(['right' => false, 'mistakeAt' => 1], LineJudge::judge(self::FLIGHTS, $solution, ['d1g4', 'g8h8', 'g4g7']), 'Played exactly without the mate option.');
        self::assertSame(['right' => false, 'mistakeAt' => 2], LineJudge::judge(self::FLIGHTS, $solution, ['d1g4', 'g8h8', 'g4g5'], true), 'The mate let go.');
        self::assertSame(['right' => false, 'mistakeAt' => 2], LineJudge::judge(self::FLIGHTS, $solution, ['d1g4', 'g8h8'], true), 'Cut short.');
    }

    public function testAMoveOffTheSolutionIsRightOnlyWhenItStillMatesByForce(): void
    {
        // Qd4 mates only after …Rd8?? (Qxd8#): …Re1+ defends. As the solution, Qg4+ mates by force.
        $trap = ['d1d4', 'e8d8', 'd4d8'];
        self::assertSame(['right' => false, 'mistakeAt' => 0], LineJudge::judge(self::FLIGHTS, ['d1g4', 'g8f8', 'g4g7'], $trap, true));
        self::assertSame(['right' => true, 'mistakeAt' => null], LineJudge::judge(self::FLIGHTS, $trap, ['d1g4', 'g8h8', 'g4g7'], true));
    }

    public function testTheSearchOutOfBudgetProvesNothing(): void
    {
        $rules = Rules::fromFen(self::FLIGHTS);
        self::assertNotNull($rules->playUci('d1g4'));
        $before = $rules->fen();
        self::assertTrue(MateSearch::forced($rules, 1));
        self::assertSame($before, $rules->fen(), 'The position is left as found.');
        try {
            MateSearch::forced($rules, 1, 2);
            self::fail('Two positions are not enough.');
        } catch (SearchBudgetExceededException) {
            self::assertSame($before, $rules->fen(), 'Left as found, out of budget too.');
        }
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
