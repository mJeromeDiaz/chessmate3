<?php

declare(strict_types=1);

namespace App\Woodpecker\Stats;

/**
 * Figures of one cycle run, computed from its attempts.
 */
final readonly class CycleStats
{
    public function __construct(
        public int $solved,
        public int $failed,
        /** Sum of attempt durations, each capped ({@see \App\Entity\Woodpecker\Attempt::ACTIVE_TIME_CAP_MS}). */
        public int $activeMs,
    ) {
    }

    public function played(): int
    {
        return $this->solved + $this->failed;
    }

    public function accuracy(): ?float
    {
        return 0 === $this->played() ? null : $this->solved / $this->played();
    }

    public function averageMs(): ?int
    {
        return 0 === $this->played() ? null : intdiv($this->activeMs, $this->played());
    }
}
