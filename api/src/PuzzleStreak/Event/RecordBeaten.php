<?php

declare(strict_types=1);

namespace App\PuzzleStreak\Event;

use App\Activity\Event\DomainEventInterface;

/**
 * Milestone: a puzzle streak beat the user's best of its ramp and topic, with at least
 * {@see \App\PuzzleStreak\StreakRules::RECORD_MIN} puzzles (docs/PUZZLE_STREAK.md).
 */
final readonly class RecordBeaten implements DomainEventInterface
{
    public function __construct(
        public string $userId,
        public string $streakId,
        public string $runId,
        /** ladder or tiers */
        public string $ramp,
        public string $topic,
        public int $length,
        /** The best before it (0: none). */
        public int $previous,
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
