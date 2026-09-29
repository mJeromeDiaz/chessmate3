<?php

declare(strict_types=1);

namespace App\Woodpecker\Set;

/**
 * The parameters of a light set (validated by the API input before reaching here). The set has
 * no schedule; $puzzleCount is its initial size, it then grows ({@see \App\Woodpecker\Light\SetGrower}).
 */
final readonly class LightConfig
{
    /**
     * @param list<string> $themes theme keys (OR); empty = any theme
     */
    public function __construct(
        public int $puzzleCount,
        public int $ratingMin,
        public int $ratingMax,
        public array $themes,
        public bool $shuffle,
    ) {
    }
}
