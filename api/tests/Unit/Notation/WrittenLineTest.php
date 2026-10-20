<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notation;

use App\Chess\Rules;
use App\Notation\Judge\WrittenLine;
use PHPUnit\Framework\TestCase;

/**
 * A puzzle's line written by the player (docs/NOTATION.md, § 3, « write »): lenient SAN, move
 * numbers allowed, compared move by move; another mating last move is right too.
 */
final class WrittenLineTest extends TestCase
{
    private const EXPECTED = ['e2e4', 'e7e5', 'g1f3'];

    public function testTheTokensLeaveMoveNumbersOut(): void
    {
        self::assertSame(['e4', 'e5', 'Nf3'], WrittenLine::tokens(['1. e4 e5', ' 2.Nf3 ']));
        self::assertSame(['Nc6'], WrittenLine::tokens(['2... Nc6']));
    }

    public function testARightLineAndItsMistakes(): void
    {
        $fen = Rules::INITIAL_FEN;
        self::assertSame(['correct' => true, 'mistakeAt' => null, 'reason' => null, 'sans' => ['e4', 'e5', 'Nf3']], WrittenLine::judge($fen, ['1. e4 e5 2. Nf3'], self::EXPECTED));
        self::assertSame(['correct' => false, 'mistakeAt' => 2, 'reason' => 'short', 'sans' => ['e4', 'e5']], WrittenLine::judge($fen, ['e4 e5'], self::EXPECTED));
        self::assertSame(['correct' => false, 'mistakeAt' => 1, 'reason' => 'illegal', 'sans' => ['e4']], WrittenLine::judge($fen, ['e4 e9'], self::EXPECTED));
        self::assertSame(['correct' => false, 'mistakeAt' => 1, 'reason' => 'wrong', 'sans' => ['e4', 'e6']], WrittenLine::judge($fen, ['e4 e6 Nf3'], self::EXPECTED));
        self::assertSame(['correct' => false, 'mistakeAt' => 3, 'reason' => 'long', 'sans' => ['e4', 'e5', 'Nf3']], WrittenLine::judge($fen, ['e4 e5 Nf3 Nc6'], self::EXPECTED));
    }

    public function testAnotherMatingLastMoveIsRight(): void
    {
        // Ra8# expected; Rb8# mates too, Rb7 does not.
        $fen = '7k/6pp/8/8/8/8/1R6/R5K1 w - - 0 1';
        self::assertTrue(WrittenLine::judge($fen, ['Ra8#'], ['a1a8'])['correct']);
        self::assertSame(['correct' => true, 'mistakeAt' => null, 'reason' => null, 'sans' => ['Rb8#']], WrittenLine::judge($fen, ['Rb8'], ['a1a8']));
        self::assertSame('wrong', WrittenLine::judge($fen, ['Rb7'], ['a1a8'])['reason']);
    }
}
