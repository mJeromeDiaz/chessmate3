<?php

declare(strict_types=1);

namespace App\Activity\Event;

use App\Enum\Activity\ExerciseType;

/**
 * One exercise finished, whatever its kind (docs/ACTIVITY.md). Published through the outbox, so a
 * subscriber only ever sees exercises whose transaction committed. Delivered at least once:
 * subscribers must be idempotent, keyed on (sourceType, sourceId).
 */
final readonly class ExerciseCompleted implements DomainEventInterface
{
    /**
     * @param string                                    $userId     user UUID (RFC 4122)
     * @param int                                       $durationMs time measured by the server
     * @param int                                       $itemCount  items in the exercise (1 for a puzzle)
     * @param string                                    $sourceType what $sourceId identifies, e.g. "puzzle_attempt"
     * @param \DateTimeImmutable                        $occurredAt UTC
     * @param array<string, scalar|list<scalar>|null>   $metadata   small, exercise-specific facts
     */
    public function __construct(
        public string $userId,
        public ExerciseType $type,
        public bool $success,
        public int $durationMs,
        public int $itemCount,
        public string $sourceType,
        public string $sourceId,
        public \DateTimeImmutable $occurredAt,
        public array $metadata = [],
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
