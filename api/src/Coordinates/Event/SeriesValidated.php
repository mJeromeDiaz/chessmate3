<?php

declare(strict_types=1);

namespace App\Coordinates\Event;

use App\Activity\Event\DomainEventInterface;

/**
 * Milestone: a coordinates series validated its orientation (docs/COORDINATES.md). Published for
 * every validating series; the XP bonus only rewards the first one of each orientation.
 */
final readonly class SeriesValidated implements DomainEventInterface
{
    public function __construct(
        public string $userId,
        public string $seriesId,
        public string $runId,
        /** white or black */
        public string $orientation,
        public int $answerCount,
        public int $successCount,
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
