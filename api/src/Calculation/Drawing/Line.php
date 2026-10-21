<?php

declare(strict_types=1);

namespace App\Calculation\Drawing;

use App\Enum\Calculation\Ending;

/**
 * A line to calculate: a puzzle's position once the opponent's first move is played, its solution
 * (UCI, both sides, the player's move first), its length in the player's moves and how it ends.
 */
final readonly class Line
{
    /**
     * @param list<string> $expected
     */
    public function __construct(
        public int $puzzleId,
        public int $rating,
        public int $moves,
        public Ending $ending,
        public string $fen,
        public array $expected,
    ) {
    }
}
