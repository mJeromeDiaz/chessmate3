<?php

declare(strict_types=1);

namespace App\Puzzle\Rating;

/**
 * A Glicko-2 rating on the public (Glicko) scale: rating ~1500, deviation (RD) in rating points.
 */
final readonly class RatingState
{
    public function __construct(
        public float $rating,
        public float $deviation,
        public float $volatility,
    ) {
    }
}
