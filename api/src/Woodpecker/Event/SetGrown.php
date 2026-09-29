<?php

declare(strict_types=1);

namespace App\Woodpecker\Event;

use App\Activity\Event\DomainEventInterface;

/**
 * Milestone: puzzles were appended to a light set, whose round was running out of unseen ones.
 */
final readonly class SetGrown implements DomainEventInterface
{
    public function __construct(
        public string $userId,
        public string $setId,
        public int $round,
        public int $added,
        public int $puzzleCount,
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
