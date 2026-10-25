<?php

declare(strict_types=1);

namespace App\Tests\Unit\Chess;

use App\Chess\Attacks;
use App\Chess\Sacrifice;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Whether a move offers material (docs/BRILLIANCY.md, § 2), on small hand-made boards.
 */
final class SacrificeTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, int|null}>
     */
    public static function moves(): iterable
    {
        // Bxh7+ Kxh7: a bishop for a pawn.
        yield 'a bishop taken by the king' => ['6k1/7p/8/8/8/3B4/8/6K1 w - - 0 1', 'd3h7', 2];
        // The queen guards h7: the king cannot take, nothing is given.
        yield 'a bishop the king cannot take' => ['6k1/7p/8/7Q/8/3B4/8/6K1 w - - 0 1', 'd3h7', 0];
        // Nd5 cxd5 exd5: a knight for a pawn.
        yield 'a knight taken by a pawn, taken back' => ['4k3/8/2p5/8/4P3/2N5/8/4K3 w - - 0 1', 'c3d5', 2];
        // Nxd5 cxd5 exd5: a knight for a knight and a pawn.
        yield 'an even trade' => ['4k3/8/2p5/3n4/4P3/2N5/8/4K3 w - - 0 1', 'c3d5', 0];
        // Qc6+: the knight that could take is pinned by the rook.
        yield 'a capturer pinned' => ['4k3/4n3/8/3Q4/8/8/8/4R1K1 w - - 0 1', 'd5c6', 0];
        yield 'the same capturer free' => ['4k3/4n3/8/3Q4/8/8/8/6K1 w - - 0 1', 'd5c6', 9];
        // Rxe5 dxe5: the exchange (a rook for a knight).
        yield 'the exchange' => ['4k3/8/3p4/4n3/8/8/8/4R1K1 w - - 0 1', 'e1e5', 2];
        // Black gives too: Qf2+ Kxf2.
        yield 'a queen taken by the king' => ['6k1/8/8/8/8/8/3q3P/6K1 b - - 0 1', 'd2f2', 9];
        yield 'a king move' => ['6k1/8/8/8/8/8/8/6K1 w - - 0 1', 'g1g2', null];
        yield 'a promotion' => ['6k1/1P6/8/8/8/8/8/6K1 w - - 0 1', 'b7b8q', null];
        yield 'an empty square' => ['6k1/8/8/8/8/8/8/6K1 w - - 0 1', 'a2a4', null];
    }

    #[DataProvider('moves')]
    public function testTheMaterialGiven(string $fen, string $uci, ?int $given): void
    {
        $board = Attacks::board($fen);
        self::assertSame($given, Sacrifice::given($board, $uci));
        self::assertSame(null !== $given && $given >= Sacrifice::MIN_GIVEN, Sacrifice::offers($board, $uci));
    }

    public function testAMoveIsPlayedOnTheBoard(): void
    {
        $castled = Sacrifice::play(Attacks::board('4k3/8/8/8/8/8/8/R3K2R w KQ - 0 1'), 'e1g1');
        self::assertSame(['a1' => 'R', 'e8' => 'k', 'f1' => 'R', 'g1' => 'K'], self::sorted($castled));
        $long = Sacrifice::play(Attacks::board('r3k3/8/8/8/8/8/8/4K3 b q - 0 1'), 'e8c8');
        self::assertSame(['c8' => 'k', 'd8' => 'r', 'e1' => 'K'], self::sorted($long));

        $enPassant = Sacrifice::play(Attacks::board('4k3/8/8/3pP3/8/8/8/4K3 w - d6 0 1'), 'e5d6');
        self::assertSame(['d6' => 'P', 'e1' => 'K', 'e8' => 'k'], self::sorted($enPassant));

        self::assertSame('n', Sacrifice::play(Attacks::board('4k3/8/8/8/8/8/1p6/4K3 b - - 0 1'), 'b2b1n')['b1'] ?? null);
    }

    public function testAMoveOfNoPieceIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Sacrifice::play(Attacks::board('4k3/8/8/8/8/8/8/4K3 w - - 0 1'), 'a1a2');
    }

    /**
     * @param array<string, string> $board
     *
     * @return array<string, string>
     */
    private static function sorted(array $board): array
    {
        ksort($board);

        return $board;
    }
}
