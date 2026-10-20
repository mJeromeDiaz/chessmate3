<?php

declare(strict_types=1);

namespace App\Tests\Unit\Vision;

use App\Vision\Board\Relations;
use PHPUnit\Framework\TestCase;

/**
 * The relations between pieces (docs/VISION.md, § 2 quater): attacked, defended or not (never the
 * king), aligned (the first piece in each direction, whatever its side).
 */
final class RelationsTest extends TestCase
{
    /** Black: Ke8, c6, Nd5; White: e4, Nc3, Ke1. */
    private const POSITION = '4k3/8/2p5/3n4/4P3/2N5/8/4K3 w - - 0 1';

    public function testAttackedAndDefended(): void
    {
        self::assertSame(['d5'], Relations::attacked(self::POSITION, 'b'), 'The knight, by the pawn and the knight; never the king.');
        self::assertSame(['c3'], Relations::attacked(self::POSITION, 'w'));
        self::assertSame(['d5'], Relations::defended(self::POSITION, 'b'), 'By the c6 pawn.');
        self::assertSame(['c6'], Relations::undefended(self::POSITION, 'b'));
        self::assertSame(['e4'], Relations::defended(self::POSITION, 'w'), 'The knight defends e4.');
        self::assertSame(['c3'], Relations::undefended(self::POSITION, 'w'));
    }

    public function testThePiecesAlignedWithOne(): void
    {
        self::assertSame(['c6', 'e4'], Relations::aligned(self::POSITION, 'd5'));
        self::assertSame(['c6', 'e1'], Relations::aligned(self::POSITION, 'c3'), 'Up its file and down its diagonal.');
        // The first piece in each direction only: the pawn hides the king behind it.
        self::assertSame(['e4'], Relations::aligned('4k3/8/8/8/4P3/8/8/4K3 w - - 0 1', 'e1'));
    }
}
