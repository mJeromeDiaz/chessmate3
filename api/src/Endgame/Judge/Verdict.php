<?php

declare(strict_types=1);

namespace App\Endgame\Judge;

use App\Enum\Endgame\AttemptStatus;

/**
 * The verdict on a game of the endgames module ({@see GameJudge}).
 */
final readonly class Verdict
{
    public function __construct(
        public AttemptStatus $status,
        public int $playerMoves,
        /** Ply (0-based) of the player's move that spoiled the result. */
        public ?int $mistakeAt = null,
        /** A move that kept the result there (UCI). */
        public ?string $bestMove = null,
        public bool $engineJudged = false,
    ) {
    }
}
