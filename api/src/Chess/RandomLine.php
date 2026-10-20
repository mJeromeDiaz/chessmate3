<?php

declare(strict_types=1);

namespace App\Chess;

use PChess\Chess\Move;
use Random\Randomizer;

/**
 * A random line of legal moves from a position, for both sides (docs/VISION.md, § 2 ter): its
 * captures where they are asked for, en passant and promotions never, and a position still alive at
 * its end (no mate, no stalemate).
 */
final readonly class RandomLine
{
    private const TRIES = 200;

    private Randomizer $randomizer;

    public function __construct(?Randomizer $randomizer = null)
    {
        $this->randomizer = $randomizer ?? new Randomizer();
    }

    /**
     * $count moves from $fen with exactly $captures of them captures (null: any number).
     *
     * @return array{moves: list<string>, captures: list<array{square: string, piece: string}>, fen: string}|null UCI moves, the pieces taken (FEN letter, where they stood) and the position reached; null when none could be drawn
     *
     * @throws InvalidPositionException
     */
    public function draw(string $fen, int $count, ?int $captures = null): ?array
    {
        for ($try = 0; $try < self::TRIES; ++$try) {
            $line = $this->once($fen, $count, $captures);
            if (null !== $line) {
                return $line;
            }
        }

        return null;
    }

    /**
     * @return array{moves: list<string>, captures: list<array{square: string, piece: string}>, fen: string}|null
     *
     * @throws InvalidPositionException
     */
    private function once(string $fen, int $count, ?int $captures): ?array
    {
        $rules = Rules::fromFen($fen);
        $moves = [];
        $taken = [];
        for ($i = 0; $i < $count; ++$i) {
            $legal = array_values(array_filter(
                $rules->legalMoves(),
                static fn (Move $m): bool => null === $m->promotion && 0 === ($m->flags & Move::BITS['EP_CAPTURE']),
            ));
            $takes = array_values(array_filter($legal, static fn (Move $m): bool => null !== $m->captured));
            $quiet = array_values(array_filter($legal, static fn (Move $m): bool => null === $m->captured));
            $left = $count - $i;
            $owed = null === $captures ? null : $captures - \count($taken);
            $pool = match (true) {
                null === $owed => $legal,
                $owed <= 0 => $quiet,
                // As many captures owed as moves left: only captures; otherwise one now and then.
                $owed >= $left => $takes,
                [] !== $takes && 0 === $this->randomizer->getInt(0, 1) => $takes,
                default => $quiet,
            };
            if ([] === $pool) {
                return null;
            }
            $move = $pool[$this->randomizer->getInt(0, \count($pool) - 1)];
            $color = 'w' === $move->turn ? 'b' : 'w';
            $played = $rules->playUci(Rules::uci($move));
            if (null === $played) {
                return null;
            }
            if (null !== $move->captured) {
                $taken[] = ['square' => $move->to, 'piece' => 'w' === $color ? strtoupper($move->captured) : $move->captured];
            }
            $moves[] = Rules::uci($move);
        }
        if ((null !== $captures && \count($taken) !== $captures) || [] === $rules->legalMoves()) {
            return null;
        }

        return ['moves' => $moves, 'captures' => $taken, 'fen' => $rules->fen()];
    }
}
