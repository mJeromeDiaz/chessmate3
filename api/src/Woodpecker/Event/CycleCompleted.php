<?php

declare(strict_types=1);

namespace App\Woodpecker\Event;

use App\Activity\Event\DomainEventInterface;

/**
 * Milestone: a cycle run was completed before its deadline (docs/ACTIVITY.md).
 */
final readonly class CycleCompleted implements DomainEventInterface
{
    public function __construct(
        public string $userId,
        public string $setId,
        public int $cycleNumber,
        public int $run,
        public int $cycleCount,
        public int $puzzleCount,
        public int $solved,
        public int $failed,
        /** Sum of attempt durations, each capped at 5 minutes. */
        public int $activeMs,
        /** From the run's start (availableAt) to its completion. */
        public int $calendarMs,
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
