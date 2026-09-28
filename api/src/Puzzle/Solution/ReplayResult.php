<?php

declare(strict_types=1);

namespace App\Puzzle\Solution;

/**
 * What the server concluded from replaying a submitted move log.
 */
final readonly class ReplayResult
{
    /**
     * @param int $mistakes wrong moves (each one was taken back before the next)
     * @param int $progress player moves of the solution found so far
     */
    public function __construct(
        public bool $completed,
        public int $mistakes,
        public int $progress,
    ) {
    }

    public function isClean(): bool
    {
        return $this->completed && 0 === $this->mistakes;
    }
}
