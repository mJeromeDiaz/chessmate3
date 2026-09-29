<?php

declare(strict_types=1);

namespace App\Woodpecker\Event;

use App\Activity\Event\DomainEventInterface;

/**
 * Milestone: a cycle run's deadline passed before its end; a new run of the same cycle started.
 */
final readonly class CycleLost implements DomainEventInterface
{
    public function __construct(
        public string $userId,
        public string $setId,
        public int $cycleNumber,
        public int $run,
        public int $played,
        public int $puzzleCount,
        public \DateTimeImmutable $deadlineAt,
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
