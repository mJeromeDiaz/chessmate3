<?php

declare(strict_types=1);

namespace App\Tests\Unit\Chess;

use App\Chess\Attacks;
use PHPUnit\Framework\TestCase;

/**
 * Which pieces attack a square (docs/VISION.md, § 3): each piece's pattern, lines stopped by the
 * first piece met, pawns forward only.
 */
final class AttacksTest extends TestCase
{
    private const POSITION = '4k3/8/8/8/3Q4/8/1B3N2/4K3 w - - 0 1';

    public function testEachPieceAttacksAlongItsPattern(): void
    {
        self::assertSame(['d4'], Attacks::attackers(self::POSITION, 'f4', 'w'), 'The queen along its rank; the knight does not reach f4.');
        self::assertSame(['d4', 'f2'], Attacks::attackers(self::POSITION, 'e4', 'w'));
        self::assertSame(['d4'], Attacks::attackers(self::POSITION, 'h8', 'w'), 'The bishop behind the queen does not attack through it.');
        self::assertSame(['b2', 'd4'], Attacks::attackers(self::POSITION, 'c3', 'w'));
        self::assertSame(['d4', 'e1', 'f2'], Attacks::attackers(self::POSITION, 'd1', 'w'), 'The king its neighbours, the knight its jumps, the queen down its file.');
        self::assertSame([], Attacks::attackers(self::POSITION, 'f4', 'b'));
    }

    public function testPawnsAttackForwardDiagonally(): void
    {
        self::assertSame(['c5'], Attacks::attackers('4k3/8/8/2p5/8/8/8/4K3 w - - 0 1', 'd4', 'b'));
        self::assertSame([], Attacks::attackers('4k3/8/8/2p5/8/8/8/4K3 w - - 0 1', 'd6', 'b'), 'Never backwards.');
        self::assertSame(['e4'], Attacks::attackers('4k3/8/8/8/4P3/8/8/4K3 w - - 0 1', 'f5', 'w'));
        self::assertSame([], Attacks::attackers('4k3/8/8/8/4P3/8/8/4K3 w - - 0 1', 'e5', 'w'), 'Not straight ahead.');
    }

    public function testTheBoardOfAFen(): void
    {
        self::assertSame(['e8' => 'k', 'd4' => 'Q', 'b2' => 'B', 'f2' => 'N', 'e1' => 'K'], Attacks::board(self::POSITION));
    }
}
