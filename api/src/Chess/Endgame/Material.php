<?php

declare(strict_types=1);

namespace App\Chess\Endgame;

use App\Enum\Chess\EndgameType;

/**
 * The material on the board, followed move by move from the initial position (captures and
 * promotions), without looking at the board (docs/COACH.md). Points: queen 9, rook 5, bishop and
 * knight 3, pawn 1. An endgame is a position where the pieces besides kings and pawns, both sides
 * together, are worth at most ENDGAME_MAX_PIECE_POINTS (rook and bishop against rook and knight:
 * 16; queen and rook each: 28, not yet).
 */
final class Material
{
    public const ENDGAME_MAX_PIECE_POINTS = 26;
    private const VALUES = ['q' => 9, 'r' => 5, 'b' => 3, 'n' => 3, 'p' => 1];

    /** @var array{w: array<string, int>, b: array<string, int>} pieces by color ("w", "b") and type ("q", "r", "b", "n", "p") */
    private array $pieces;

    public function __construct()
    {
        $start = ['q' => 1, 'r' => 2, 'b' => 2, 'n' => 2, 'p' => 8];
        $this->pieces = ['w' => $start, 'b' => $start];
    }

    /**
     * A move played by $turn ("w", "b"): the piece it captured, the piece a pawn became (p-chess
     * types: "q", "r", "b", "n", "p").
     */
    public function play(string $turn, ?string $captured, ?string $promotion): void
    {
        $mover = 'b' === $turn ? 'b' : 'w';
        $opponent = 'w' === $mover ? 'b' : 'w';
        if (null !== $captured && isset($this->pieces[$opponent][$captured])) {
            $this->pieces[$opponent][$captured] = max(0, $this->pieces[$opponent][$captured] - 1);
        }
        if (null !== $promotion && isset($this->pieces[$mover][$promotion])) {
            $this->pieces[$mover]['p'] = max(0, $this->pieces[$mover]['p'] - 1);
            ++$this->pieces[$mover][$promotion];
        }
    }

    /** Points of a side ("w", "b"), pawns included. */
    public function points(string $color): int
    {
        $total = 0;
        foreach ($this->pieces['b' === $color ? 'b' : 'w'] as $type => $count) {
            $total += self::VALUES[$type] * $count;
        }

        return $total;
    }

    /** White's points minus Black's. */
    public function balance(): int
    {
        return $this->points('w') - $this->points('b');
    }

    /** Points of the pieces besides kings and pawns, both sides together. */
    public function piecePoints(): int
    {
        return $this->points('w') + $this->points('b') - $this->pieces['w']['p'] - $this->pieces['b']['p'];
    }

    public function isEndgame(): bool
    {
        return $this->piecePoints() <= self::ENDGAME_MAX_PIECE_POINTS;
    }

    public function type(): EndgameType
    {
        $count = fn (string $type): int => $this->pieces['w'][$type] + $this->pieces['b'][$type];
        $queens = $count('q');
        $rooks = $count('r');
        $minors = $count('b') + $count('n');

        return match (true) {
            0 === $queens + $rooks + $minors => EndgameType::Pawn,
            $queens > 0 => 0 === $rooks + $minors ? EndgameType::Queen : EndgameType::Mixed,
            0 === $minors => EndgameType::Rook,
            0 === $rooks => EndgameType::Minor,
            default => EndgameType::RookMinor,
        };
    }
}
