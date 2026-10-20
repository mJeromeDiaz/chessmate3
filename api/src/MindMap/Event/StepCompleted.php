<?php

declare(strict_types=1);

namespace App\MindMap\Event;

use App\Activity\Event\DomainEventInterface;

/**
 * Milestone: a step of a mind map path completed (docs/MIND_MAP.md, § 6): an exercise's last pass,
 * a boss beaten, a chest earned. Its XP bonus is awarded by Gamification, once per progress row.
 */
final readonly class StepCompleted implements DomainEventInterface
{
    public function __construct(
        public string $userId,
        /** The progress row (the XP's source). */
        public string $progressId,
        public string $capacity,
        public string $step,
        /** exercise, chest, mini_boss, hidden_boss or boss */
        public string $kind,
        public int $xp,
        public int $crystals,
        /** The run that completed it, empty for a chest. */
        public string $runId,
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
