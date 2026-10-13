<?php

declare(strict_types=1);

namespace App\Tests\Unit\Chess\Endgame;

use App\Chess\Endgame\EndgameScanner;
use App\Chess\Endgame\Material;
use App\Chess\Rules;
use App\Enum\Chess\EndgameType;
use PHPUnit\Framework\TestCase;

/**
 * Material followed move by move, the endgame threshold and kinds, and where a game enters its
 * endgame (docs/COACH.md).
 */
final class EndgameTest extends TestCase
{
    /** A Ruy Lopez played to move 43: queens off on ply 55-56, the endgame after the quiet 57th. */
    public const RUY_LOPEZ = 'e4 e5 Nf3 Nc6 Bb5 a6 Ba4 Nf6 O-O Be7 Re1 b5 Bb3 d6 c3 O-O h3 Nb8 d4 Nbd7 c4 c6 cxb5 axb5 Nc3 Bb7 Bg5 b4 Nb1 h6 Bh4 c5 dxe5 Nxe4 Bxe7 Qxe7 exd6 Qf6 Nbd2 Nxd6 Nc4 Nxc4 Bxc4 Nb6 Ne5 Rae8 Bxf7+ Rxf7 Nxf7 Rxe1+ Qxe1 Kxf7 Qe3 Qg5 Qxg5 hxg5 b3 Ke6 a3 Kd6 axb4 cxb4 Ra5 Nd5 f3 Bc8 Kf2 Bf5 Ra7 g6 Ra6+ Kc5 Ke1 Nf4 g3 Nxh3 Kd2 Kb5 Rd6 Kc5 Ra6 Nf2 g4 Bd3 Re6';

    public function testMaterialFollowsCapturesAndPromotions(): void
    {
        $material = new Material();
        self::assertSame([39, 39, 0, 62, false], [$material->points('w'), $material->points('b'), $material->balance(), $material->piecePoints(), $material->isEndgame()]);

        $material->play('w', 'n', null);
        self::assertSame([36, 3], [$material->points('b'), $material->balance()]);
        // Black takes a pawn and promotes it (capturing a rook on the way).
        $material->play('b', 'p', null);
        $material->play('b', 'r', 'q');
        self::assertSame([33, 44, -11], [$material->points('w'), $material->points('b'), $material->balance()]);
    }

    public function testTheThresholdAndTheKinds(): void
    {
        $material = self::without(['w' => ['q', 'b', 'b', 'n', 'n'], 'b' => ['q', 'b', 'b', 'n', 'n']]);
        self::assertSame([20, true, EndgameType::Rook], [$material->piecePoints(), $material->isEndgame(), $material->type()]);
        // Queen and rook each: 28 points, still a middlegame.
        $queenAndRook = self::without(['w' => ['r', 'b', 'b', 'n', 'n'], 'b' => ['r', 'b', 'b', 'n', 'n']]);
        self::assertSame([28, false], [$queenAndRook->piecePoints(), $queenAndRook->isEndgame()]);

        self::assertSame(EndgameType::Pawn, self::without(['w' => ['q', 'r', 'r', 'b', 'b', 'n', 'n'], 'b' => ['q', 'r', 'r', 'b', 'b', 'n', 'n']])->type());
        self::assertSame(EndgameType::Minor, self::without(['w' => ['q', 'r', 'r', 'n'], 'b' => ['q', 'r', 'r', 'b', 'b']])->type());
        self::assertSame(EndgameType::Queen, self::without(['w' => ['r', 'r', 'b', 'b', 'n', 'n'], 'b' => ['r', 'r', 'b', 'b', 'n', 'n']])->type());
        self::assertSame(EndgameType::RookMinor, self::without(['w' => ['q', 'r', 'b', 'n', 'n'], 'b' => ['q', 'r', 'b', 'b', 'n']])->type());
        self::assertSame(EndgameType::Mixed, self::without(['w' => ['r', 'r', 'b', 'b', 'n'], 'b' => ['r', 'r', 'b', 'b', 'n', 'n']])->type());
    }

    public function testAGameEntersItsEndgameOnceTheExchangeIsOver(): void
    {
        $scanner = self::scan(self::RUY_LOPEZ);

        // Not after Qxg5 (a queen up for one ply) nor hxg5: after b3, rook, bishop and knight
        // against rook and knight... both sides level.
        self::assertSame([EndgameType::RookMinor, 57, 0], [$scanner->type(), $scanner->ply(), $scanner->balance()]);
    }

    public function testAShortGameHasNoEndgame(): void
    {
        $scanner = self::scan('e4 e5 Bc4 Nc6 Qh5 Nf6 Qxf7#');

        self::assertSame([null, null, null], [$scanner->type(), $scanner->ply(), $scanner->balance()]);
    }

    /**
     * Material after the opponent took these pieces of each side (p-chess types).
     *
     * @param array{w: list<string>, b: list<string>} $taken
     */
    private static function without(array $taken): Material
    {
        $material = new Material();
        foreach ($taken['w'] as $type) {
            $material->play('b', $type, null);
        }
        foreach ($taken['b'] as $type) {
            $material->play('w', $type, null);
        }

        return $material;
    }

    private static function scan(string $sans): EndgameScanner
    {
        $rules = Rules::initial();
        $scanner = new EndgameScanner();
        foreach (explode(' ', $sans) as $san) {
            $move = $rules->playSan($san);
            self::assertNotNull($move, $san);
            $scanner->play($move);
        }

        return $scanner;
    }
}
