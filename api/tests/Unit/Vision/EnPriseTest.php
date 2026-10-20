<?php

declare(strict_types=1);

namespace App\Tests\Unit\Vision;

use App\Vision\Board\EnPrise;
use PHPUnit\Framework\TestCase;

/**
 * The pieces en prise (docs/VISION.md, § 3): what the side to move can take, undefended or by a
 * piece worth less.
 */
final class EnPriseTest extends TestCase
{
    public function testAnUndefendedPieceIsEnPrise(): void
    {
        self::assertSame(['d5'], EnPrise::squares('4k3/8/8/3n4/8/2N5/8/4K3 w - - 0 1'));
    }

    public function testADefendedPieceTakenByAnEqualIsNot(): void
    {
        self::assertSame([], EnPrise::squares('4k3/8/2p5/3n4/8/2N5/8/4K3 w - - 0 1'));
    }

    public function testADefendedPieceTakenByALesserOneIs(): void
    {
        self::assertSame(['d5'], EnPrise::squares('4k3/8/2p5/3n4/4P3/8/8/4K3 w - - 0 1'));
    }

    public function testThePositionOfTheTestPuzzle(): void
    {
        // After Nd4 Nd5 Ne2+ Kh1: the f2 pawn, attacked by the c5 bishop, has no defender.
        self::assertSame(['f2'], EnPrise::squares('5rk1/5pp1/8/2bN4/2B5/8/4nPP1/R6K b - - 4 3'));
    }
}
