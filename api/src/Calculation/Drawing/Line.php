<?php

declare(strict_types=1);

namespace App\Calculation\Drawing;

/**
 * A line to calculate: a mate puzzle's position once the opponent's first move is played, and its
 * solution (UCI, both sides, the player's move first).
 */
final readonly class Line
{
    /**
     * @param list<string> $expected
     */
    public function __construct(
        public int $puzzleId,
        public int $rating,
        public int $mate,
        public string $fen,
        public array $expected,
    ) {
    }
}
