<?php

declare(strict_types=1);

namespace App\Training\Event;

use App\Activity\Event\DomainEventInterface;

/**
 * Milestone: a timed run closed (docs/TRAINING.md), whatever the reason. $occurredAt is the
 * closing instant: the expiry for a run whose time ran out, even when closed later (lazily).
 */
final readonly class RunCompleted implements DomainEventInterface
{
    public function __construct(
        public string $userId,
        public string $runId,
        public string $module,
        public string $subjectType,
        public string $subjectId,
        public ?string $parentId,
        public string $reason,
        public int $budgetSeconds,
        public int $durationMs,
        public int $itemCount,
        public int $successCount,
        public \DateTimeImmutable $startedAt,
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
