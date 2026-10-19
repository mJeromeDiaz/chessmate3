<?php

declare(strict_types=1);

namespace App\Tests\Unit\Blindfold;

use App\Blindfold\Recall\PuzzlePositionPicker;
use App\Entity\Catalog\Puzzle;
use PHPUnit\Framework\TestCase;

/**
 * A puzzle's position for a recall level (docs/BLINDFOLD_RECALL.md): shown after the opponent's
 * first move, rebuilt after the moves announced.
 */
final class PuzzlePositionTest extends TestCase
{
    private const FEN = '5rk1/5ppp/2n5/2b5/2B5/2N5/5PPP/5RK1 b - - 0 1';

    public function testShownAfterTheFirstMoveRebuiltAfterTheMovesAnnounced(): void
    {
        $plain = PuzzlePositionPicker::position(self::puzzle('c6d4 c3d5 d4e2 g1h1'), 0);
        self::assertNotNull($plain);
        self::assertSame('5rk1/5ppp/8/2b5/2Bn4/2N5/5PPP/5RK1 w - - 1 2', $plain->fen);
        self::assertSame('5rk1/5ppp/8/2b5/2Bn4/2N5/5PPP/5RK1', $plain->expectedPlacement);
        self::assertSame([], $plain->announced);

        $three = PuzzlePositionPicker::position(self::puzzle('c6d4 c3d5 d4e2 g1h1'), 3);
        self::assertNotNull($three);
        self::assertSame($plain->fen, $three->fen);
        self::assertSame('5rk1/5ppp/8/2bN4/2B5/8/4nPPP/5R1K', $three->expectedPlacement);
        self::assertSame(['c3d5', 'd4e2', 'g1h1'], $three->announced);
    }

    public function testTooShortOrUnplayableIsLeftOut(): void
    {
        self::assertNull(PuzzlePositionPicker::position(self::puzzle('c6d4 c3d5'), 3), 'not enough moves');
        self::assertNull(PuzzlePositionPicker::position(self::puzzle('c6d4 c3d6 d4e2 g1h1'), 1), 'an illegal move');
    }

    private static function puzzle(string $moves): Puzzle
    {
        return new Puzzle('RC001', self::FEN, $moves, 1500, 80, 95, 5000, ['middlegame'], 'https://lichess.org/abcdefgh', null);
    }
}
