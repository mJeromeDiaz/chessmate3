<?php

declare(strict_types=1);

namespace App\Endgame\Tablebase;

use App\Enum\Endgame\Category;
use App\Enum\Endgame\Result;

/**
 * One legal move of a tablebase answer. Lichess describes the position the move reaches, for the
 * side to move THERE (the opponent): a winning move has the category `loss` and a negative DTZ.
 */
final readonly class MoveAnswer
{
    public function __construct(
        public string $uci,
        /** For the opponent, after the move. */
        public Category $category,
        public ?int $dtz,
        public ?int $dtm,
        /** A capture or a pawn move: the 50-move counter starts again. */
        public bool $zeroing,
        public bool $checkmate,
        public bool $stalemate,
    ) {
    }

    /** The result for the side that plays the move, null when unknown. */
    public function resultForMover(): ?Result
    {
        return $this->category->result()?->flip();
    }

    /** Plies to mate after this move (absolute), null when unknown or not a mate. */
    public function pliesToMate(): ?int
    {
        return null === $this->dtm ? null : abs($this->dtm);
    }
}
