<?php

declare(strict_types=1);

namespace App\Puzzle\Rating;

/**
 * One game of a rating period, from the rated player's point of view.
 */
final readonly class GameResult
{
    public const WIN = 1.0;
    public const LOSS = 0.0;

    /**
     * @param float $score 1 = win, 0.5 = draw, 0 = loss
     */
    public function __construct(
        public float $opponentRating,
        public float $opponentDeviation,
        public float $score,
    ) {
    }
}
