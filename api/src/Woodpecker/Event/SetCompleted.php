<?php

declare(strict_types=1);

namespace App\Woodpecker\Event;

use App\Activity\Event\DomainEventInterface;

/**
 * Milestone: the last cycle of a set was completed.
 */
final readonly class SetCompleted implements DomainEventInterface
{
    public function __construct(
        public string $userId,
        public string $setId,
        public int $cycleCount,
        public int $puzzleCount,
        /** Runs lost along the way (0 when every cycle was done in time at the first run). */
        public int $lostRuns,
        public \DateTimeImmutable $occurredAt,
    ) {
    }

    public function getUserId(): string
    {
        return $this->userId;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
