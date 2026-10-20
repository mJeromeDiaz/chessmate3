<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notation;

use App\Notation\Drawing\MoveChooser;
use PHPUnit\Framework\TestCase;

/**
 * The moves of a position by kind (docs/NOTATION.md, § 3, « play »): every case of the notation
 * can come up.
 */
final class MoveChooserTest extends TestCase
{
    public function testTheKindsOfMoves(): void
    {
        // White: castling both sides, a pawn to promote, two knights on the same square.
        $byKind = MoveChooser::byKind('r3k3/1P6/8/8/3p4/2N3N1/8/R3K2R w KQq - 0 1');

        self::assertContains(['uci' => 'e1g1', 'san' => 'O-O'], $byKind['castling']);
        self::assertContains(['uci' => 'e1c1', 'san' => 'O-O-O'], $byKind['castling']);
        self::assertContains('b7b8q', array_column($byKind['promotion'], 'uci'));
        self::assertContains('b7a8q', array_column($byKind['promotion'], 'uci'), 'A capture that promotes is a promotion.');
        self::assertContains('Nce2', array_column($byKind['disambiguated'], 'san'));
        self::assertContains('Nge2', array_column($byKind['disambiguated'], 'san'));
        self::assertArrayHasKey('piece', $byKind);
        foreach ($byKind as $moves) {
            self::assertNotEmpty($moves);
        }
    }

    public function testTheMoveChosenIsLegal(): void
    {
        $move = (new MoveChooser())->choose('r3k3/1P6/8/8/3p4/2N3N1/8/R3K2R w KQq - 0 1');

        self::assertNotNull($move);
        $all = array_merge(...array_values(MoveChooser::byKind('r3k3/1P6/8/8/3p4/2N3N1/8/R3K2R w KQq - 0 1')));
        self::assertContains($move, $all);
        self::assertNull((new MoveChooser())->choose('7k/5Q2/6K1/8/8/8/8/8 b - - 0 1'), 'Stalemate: no move.');
    }
}
