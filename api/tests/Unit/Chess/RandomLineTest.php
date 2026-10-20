<?php

declare(strict_types=1);

namespace App\Tests\Unit\Chess;

use App\Chess\Attacks;
use App\Chess\RandomLine;
use App\Chess\Rules;
use PHPUnit\Framework\TestCase;

/**
 * A random line of legal moves (docs/VISION.md, § 2 ter): its count, its captures where asked,
 * the pieces taken and the position reached, both sides alive at its end.
 */
final class RandomLineTest extends TestCase
{
    private const POSITION = '4k3/2p2p2/3n4/4b3/3N1P2/2B5/2P2P2/4K3 w - - 0 1';

    public function testALineWithItsCaptures(): void
    {
        $lines = new RandomLine();
        foreach ([[3, 1], [5, 2], [4, 0], [6, null]] as [$count, $captures]) {
            $line = $lines->draw(self::POSITION, $count, $captures);
            self::assertNotNull($line, "$count moves, $captures captures");
            self::assertCount($count, $line['moves']);
            if (null !== $captures) {
                self::assertCount($captures, $line['captures']);
            }
            $rules = Rules::fromFen(self::POSITION);
            $taken = [];
            foreach ($line['moves'] as $uci) {
                $board = Attacks::board($rules->fen());
                $there = $board[substr($uci, 2, 2)] ?? null;
                self::assertNotNull($rules->playUci($uci), $uci);
                if (null !== $there) {
                    $taken[] = ['square' => substr($uci, 2, 2), 'piece' => $there];
                }
            }
            self::assertSame($taken, $line['captures']);
            self::assertSame($rules->fen(), $line['fen']);
            self::assertNotEmpty($rules->legalMoves(), 'No mate, no stalemate at its end.');
        }
    }

    public function testNoLineWhenItsCapturesCannotBe(): void
    {
        // Two kings only: nothing to take.
        self::assertNull((new RandomLine())->draw('4k3/8/8/8/8/8/8/4K3 w - - 0 1', 3, 1));
    }
}
