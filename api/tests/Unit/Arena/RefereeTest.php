<?php

declare(strict_types=1);

namespace App\Tests\Unit\Arena;

use App\Arena\Game\Referee;
use App\Enum\Arena\GameEnd;
use PHPUnit\Framework\TestCase;

/**
 * How a game of the arena stands after its moves (docs/ARENA.md, § 3).
 */
final class RefereeTest extends TestCase
{
    public function testLegalMovesGoOnAndAnIllegalOneStops(): void
    {
        self::assertSame(['legal' => true, 'end' => null], array_intersect_key(Referee::judge(['e2e4', 'e7e5']), ['legal' => 1, 'end' => 1]));
        $judged = Referee::judge(['e2e4', 'e2e4']);
        self::assertFalse($judged['legal']);
        self::assertStringStartsWith('rnbqkbnr/pppppppp/8/8/4P3/', $judged['fen'], 'the position before the illegal move');
        self::assertFalse(Referee::judge(['e7e5'])['legal'], 'not the side to move');
        self::assertTrue(Referee::judge([])['legal']);
    }

    public function testMateAndStalemate(): void
    {
        self::assertSame(GameEnd::Checkmate, Referee::judge(['f2f3', 'e7e5', 'g2g4', 'd8h4'])['end'], "the fool's mate");
        // Sam Loyd's ten-move stalemate.
        self::assertSame(GameEnd::Stalemate, Referee::judge([
            'e2e3', 'a7a5', 'd1h5', 'a8a6', 'h5a5', 'h7h5', 'h2h4', 'a6h6', 'a5c7', 'f7f6',
            'c7d7', 'e8f7', 'd7b7', 'd8d3', 'b7b8', 'd3h7', 'b8c8', 'f7g6', 'c8e6',
        ])['end']);
    }

    public function testThreefoldRepetitionFiftyMovesAndBareKings(): void
    {
        $knights = ['g1f3', 'g8f6', 'f3g1', 'f6g8'];
        self::assertNull(Referee::judge($knights)['end'], 'twice');
        self::assertSame(GameEnd::Repetition, Referee::judge([...$knights, ...$knights])['end'], 'the initial position a third time');
        // The hundredth half-move without a capture or a pawn move.
        self::assertNull(Referee::judge(['d1b1'], '8/8/8/4k3/8/8/3K4/3R4 w - - 98 80')['end']);
        self::assertSame(GameEnd::FiftyMoves, Referee::judge(['d1a1'], '8/8/8/4k3/8/8/3K4/3R4 w - - 99 80')['end']);
        // The king takes the last piece: bare kings.
        self::assertSame(GameEnd::InsufficientMaterial, Referee::judge(['d2d1'], '8/8/8/4k3/8/8/3K4/3q4 w - - 0 60')['end']);
    }
}
