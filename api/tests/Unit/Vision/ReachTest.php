<?php

declare(strict_types=1);

namespace App\Tests\Unit\Vision;

use App\Vision\Board\Reach;
use PHPUnit\Framework\TestCase;

/**
 * Whether a piece can go to a square in one move (docs/VISION.md, § 3): its line clear, an empty
 * square or an opponent's piece, never its own nor the king.
 */
final class ReachTest extends TestCase
{
    private const POSITION = '4k3/8/8/3p4/8/1N6/8/B3K2R w - - 0 1';

    public function testAPieceReachesWhatItAttacks(): void
    {
        self::assertTrue(Reach::canReach(self::POSITION, 'a1', 'c3'), 'The bishop along its diagonal.');
        self::assertTrue(Reach::canReach(self::POSITION, 'b3', 'd4'), 'The knight jumps.');
        self::assertTrue(Reach::canReach(self::POSITION, 'h1', 'h8'), 'The rook up its file.');
        self::assertFalse(Reach::canReach(self::POSITION, 'h1', 'd1'), 'The king stands in between.');
        self::assertTrue(Reach::canReach(self::POSITION, 'a1', 'd4'), 'Further along it.');
        self::assertFalse(Reach::canReach(self::POSITION, 'a1', 'a4'), 'Not on its diagonal.');
        self::assertFalse(Reach::canReach(self::POSITION, 'e1', 'e8'), 'Not that far.');
    }

    public function testNeverItsOwnPieceNorTheKingNorAPawn(): void
    {
        self::assertFalse(Reach::canReach(self::POSITION, 'h1', 'e1'), 'Its own king.');
        self::assertTrue(Reach::canReach('4k3/8/8/8/8/8/8/4K2R w - - 0 1', 'h1', 'h8'), 'Nothing in the way, h8 empty: yes…');
        self::assertFalse(Reach::canReach('7k/8/8/8/8/8/8/4K2R w - - 0 1', 'h1', 'h8'), '…but never the king.');
        self::assertFalse(Reach::canReach('4k3/8/8/3p4/8/8/8/4K3 b - - 0 1', 'd5', 'd4'), 'A pawn is never asked about.');
    }
}
