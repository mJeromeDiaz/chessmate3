<?php

declare(strict_types=1);

namespace App\Woodpecker\Light;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Sizes of a light set (docs/WOODPECKER.md): it starts at $initialSize and grows by $batchSize
 * whenever fewer than $threshold of its puzzles remain unseen in the round being played, never
 * beyond $maxSize (strict server-side cap).
 */
final class GrowthPolicy
{
    public function __construct(
        #[Autowire('%woodpecker.light.initial_puzzles%')]
        public readonly int $initialSize,
        #[Autowire('%woodpecker.light.growth_batch%')]
        public readonly int $batchSize,
        #[Autowire('%woodpecker.light.growth_threshold%')]
        public readonly int $threshold,
        #[Autowire('%woodpecker.light.max_puzzles%')]
        public readonly int $maxSize,
    ) {
    }

    /**
     * How many puzzles to add now: 0 while enough remain unseen, never past the maximum size.
     */
    public function batchFor(int $size, int $unseen): int
    {
        if ($unseen >= $this->threshold) {
            return 0;
        }

        return max(0, min($this->batchSize, $this->maxSize - $size));
    }
}
