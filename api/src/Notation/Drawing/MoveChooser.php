<?php

declare(strict_types=1);

namespace App\Notation\Drawing;

use App\Chess\InvalidPositionException;
use App\Chess\Rules;
use PChess\Chess\Move;
use PChess\Chess\Piece;
use Random\Randomizer;

/**
 * The move to announce in a position (docs/NOTATION.md, § 3, « play »): a legal move drawn so that
 * every case of the notation comes up, not only the most frequent: first a kind of move among
 * those the position has (castling, promotion, capture, a move that needs its file or rank, a
 * check, another piece move, a pawn move), then a move of that kind.
 */
final readonly class MoveChooser
{
    private Randomizer $randomizer;

    public function __construct(?Randomizer $randomizer = null)
    {
        $this->randomizer = $randomizer ?? new Randomizer();
    }

    /**
     * @return array{uci: string, san: string}|null null when the position has no legal move
     *
     * @throws InvalidPositionException
     */
    public function choose(string $fen): ?array
    {
        $byKind = self::byKind($fen);
        if ([] === $byKind) {
            return null;
        }
        $kinds = array_keys($byKind);
        $moves = $byKind[$kinds[$this->randomizer->getInt(0, \count($kinds) - 1)]];

        return $moves[$this->randomizer->getInt(0, \count($moves) - 1)];
    }

    /**
     * The legal moves of a position, with their SAN, by kind (a move has one kind, the first that
     * fits in this order).
     *
     * @return array<string, non-empty-list<array{uci: string, san: string}>>
     *
     * @throws InvalidPositionException
     */
    public static function byKind(string $fen): array
    {
        $rules = Rules::fromFen($fen);
        $byKind = [];
        foreach ($rules->legalMoves() as $legal) {
            $uci = Rules::uci($legal);
            $played = $rules->playUci($uci);
            if (null === $played) {
                continue;
            }
            $san = $played->san ?? $uci;
            $rules->undo();
            $byKind[self::kind($played, $san)][] = ['uci' => $uci, 'san' => $san];
        }

        return $byKind;
    }

    private static function kind(Move $move, string $san): string
    {
        $piece = $move->piece->getType();

        return match (true) {
            str_starts_with($san, 'O-O') => 'castling',
            null !== $move->promotion => 'promotion',
            str_ends_with($san, '+') || str_ends_with($san, '#') => 'check',
            // A piece move written with its file or rank: « Nbd2 », « R1e2 ».
            Piece::PAWN !== $piece && 1 === preg_match('/^[KQRBN][a-h1-8]x?[a-h][1-8]/', $san) => 'disambiguated',
            null !== $move->captured => 'capture',
            Piece::PAWN === $piece => 'pawn',
            default => 'piece',
        };
    }
}
