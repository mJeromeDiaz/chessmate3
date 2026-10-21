<?php

declare(strict_types=1);

namespace App\Tests\Unit\Calculation;

use App\Calculation\Drawing\Forcing;
use App\Calculation\Drawing\LinePicker;
use App\Calculation\Drawing\LineSpec;
use App\Calculation\Drawing\Threat;
use App\Calculation\Judge\IllegalLineException;
use App\Calculation\Judge\LineShape;
use App\Chess\Rules;
use App\Entity\Catalog\Puzzle;
use App\Enum\Calculation\Ending;
use App\Enum\Calculation\Kind;
use PHPUnit\Framework\TestCase;

/**
 * Chapter 3 of the calculation path (docs/CALCULATION.md, § 2 quater): the puzzles each kind draws,
 * and what the server checks of a line the engine judged in the browser.
 */
final class EngineKindsTest extends TestCase
{
    /** After a7a6, Rd8+ leaves black two replies, Kh7 and Re8; then Rxe8+. */
    public const TWO_REPLIES = ['6k1/p4pp1/7p/8/8/8/4rPPP/3R2K1 b - - 0 1', 'a7a6 d1d8 e2e8 d8e8'];
    /** After a8e8, black threatens Re1#: h3 gives the king air. */
    public const DEFENCE = ['r5k1/5ppp/8/8/8/8/5PPP/6K1 b - - 0 1', 'a8e8 h2h3'];
    /** After h7h6, Rxd7 is met by Qb1+ first, then Rd1. */
    public const INTERMEZZO = ['6k1/3r1ppp/1q6/8/8/8/5PPP/3R2K1 b - - 0 1', 'h7h6 d1d7 b6b1 d7d1'];
    /** After a7a6, three forcing moves: Rd8+ (the solution), Bxf7+ and Bxa6. */
    public const COMBO = ['6k1/p4pp1/7p/4r3/2B5/8/5PPP/3R2K1 b - - 0 1', 'a7a6 d1d8 e5e8 d8e8'];

    public function testAMateThreatIsSeenFromTheSideNotToMove(): void
    {
        self::assertTrue(Threat::mateInOne(self::start(self::DEFENCE)), 'Re1#.');
        self::assertFalse(Threat::mateInOne(self::start(self::TWO_REPLIES)), 'Re1+ is met by Rxe1.');
        // In check: a check, no threat.
        self::assertFalse(Threat::mateInOne('6k1/5ppp/8/8/8/8/5PPP/4r1K1 w - - 0 1'));
    }

    public function testEachKindDrawsThePuzzlesThatFitIt(): void
    {
        $two = self::puzzle(self::TWO_REPLIES);
        $defence = self::puzzle(self::DEFENCE);
        $intermezzo = self::puzzle(self::INTERMEZZO);

        self::assertNotNull(LinePicker::line($two, self::spec(Kind::TwoReplies, [2])));
        self::assertNull(LinePicker::line($intermezzo, self::spec(Kind::TwoReplies, [2])), 'More than two replies to Rxd7.');
        self::assertNotNull(LinePicker::line($two, self::spec(Kind::Reply, [2])));
        self::assertNotNull(LinePicker::line($intermezzo, self::spec(Kind::Intermezzo, [2])));
        self::assertNull(LinePicker::line($two, self::spec(Kind::Intermezzo, [2])), 'Re8 gives no check.');
        self::assertNotNull(LinePicker::line($defence, self::spec(Kind::Defence, [1])));
        self::assertNull(LinePicker::line($two, self::spec(Kind::Defence, [2])), 'No mate threatened.');
        self::assertNull(LinePicker::line($defence, self::spec(Kind::Reply, [1])), 'No reply to choose in a line of one half-move.');
        self::assertSame(['defensiveMove'], self::spec(Kind::Defence, [1, 2])->drawThemes());
        self::assertSame(['short', 'long'], self::spec(Kind::Reply, [2, 3])->drawThemes());

        $line = LinePicker::line($two, self::spec(Kind::TwoReplies, [2]));
        self::assertNotNull($line);
        self::assertSame([2, Ending::Material, ['d1d8', 'e2e8', 'd8e8']], [$line->moves, $line->ending, $line->expected]);
    }

    public function testTheMiniBossDrawsAForcingFirstMoveAmongOthers(): void
    {
        $forcing = Forcing::moves(self::start(self::COMBO));
        sort($forcing);
        self::assertSame(['c4a6', 'c4f7', 'd1d8'], $forcing, 'Bxa6, Bxf7+ and Rd8+.');
        self::assertSame(['d1d8'], Forcing::moves(self::start(self::TWO_REPLIES)), 'Rd8+ alone.');

        self::assertNotNull(LinePicker::line(self::puzzle(self::COMBO), self::spec(Kind::Combo, [2])));
        self::assertNull(LinePicker::line(self::puzzle(self::TWO_REPLIES), self::spec(Kind::Combo, [2])), 'No other forcing move to offer.');
        self::assertNull(LinePicker::line(self::puzzle(self::DEFENCE), self::spec(Kind::Combo, [1])), 'h3 is no forcing move.');
    }

    public function testTheAvocatDrawsALineWithAReplyToFind(): void
    {
        self::assertNotNull(LinePicker::line(self::puzzle(self::INTERMEZZO), self::spec(Kind::Advocate, [2])));
        self::assertNull(LinePicker::line(self::puzzle(self::DEFENCE), self::spec(Kind::Advocate, [1])), 'No reply to find in a line of one half-move.');
        self::assertTrue(Kind::Advocate->isEngine());
    }

    public function testALineIsCompleteToItsLengthOrOnThePlayersMate(): void
    {
        $fen = self::start(self::TWO_REPLIES);
        self::assertTrue(LineShape::complete($fen, ['d1d8', 'e2e8', 'd8e8'], 3));
        self::assertTrue(LineShape::complete($fen, ['d1d8', 'g8h7', 'd8d7'], 3), 'Another line, to its length: the engine judges it.');
        self::assertFalse(LineShape::complete($fen, ['d1d8', 'g8h7'], 3), 'Cut short.');
        self::assertSame(['g8h7', 'e2e8'], self::sorted(LineShape::replies($fen, 'd1d8')));

        // A mate by the player ends the line sooner.
        $mate = '6k1/5ppp/8/8/8/8/5PPP/3R2K1 w - - 0 1';
        self::assertTrue(LineShape::complete($mate, ['d1d8'], 3));
    }

    public function testAnIllegalOrTooLongLineIsRefused(): void
    {
        $fen = self::start(self::TWO_REPLIES);
        foreach ([['d1d8', 'a1a8'], ['d1d8', 'e2e8', 'd8e8', 'g8h7']] as $line) {
            try {
                LineShape::complete($fen, $line, 3);
                self::fail('Refused: '.implode(' ', $line));
            } catch (IllegalLineException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /**
     * @param non-empty-list<int> $moves
     */
    private static function spec(Kind $kind, array $moves): LineSpec
    {
        return new LineSpec($moves, null, [], false, $kind);
    }

    /**
     * @param array{string, string} $puzzle
     */
    private static function puzzle(array $puzzle): Puzzle
    {
        return new Puzzle('TEST1', $puzzle[0], $puzzle[1], 1500, 80, 95, 5000, ['middlegame'], 'https://lichess.org/abcdefgh', null);
    }

    /**
     * The position once the opponent's first move is played.
     *
     * @param array{string, string} $puzzle
     */
    private static function start(array $puzzle): string
    {
        $rules = Rules::fromFen($puzzle[0]);
        self::assertNotNull($rules->playUci(explode(' ', $puzzle[1])[0]));

        return $rules->fen();
    }

    /**
     * @param list<string> $moves
     *
     * @return list<string>
     */
    private static function sorted(array $moves): array
    {
        rsort($moves);

        return $moves;
    }
}
