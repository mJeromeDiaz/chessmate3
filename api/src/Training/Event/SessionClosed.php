<?php

declare(strict_types=1);

namespace App\Training\Event;

use App\Activity\Event\DomainEventInterface;

/**
 * Milestone: a training session ended (docs/TRAINING.md): completed, abandoned, or expired (not
 * finished on its local day, noticed lazily). $durationMs is the time played in its runs.
 */
final readonly class SessionClosed implements DomainEventInterface
{
    public function __construct(
        public string $userId,
        public string $sessionId,
        public string $status,
        public int $stepCount,
        public int $doneCount,
        public int $skippedCount,
        public int $durationMs,
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
