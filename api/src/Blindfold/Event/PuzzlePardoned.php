<?php

declare(strict_types=1);

namespace App\Blindfold\Event;

use App\Activity\Event\DomainEventInterface;

/**
 * A blindfold puzzle missed, then pardoned with the store's pardon on the miss sheet
 * (docs/BLINDFOLD.md): it counts as solved, an XP top-up follows. `occurredAt` is the miss (the
 * top-up joins its local day).
 */
final readonly class PuzzlePardoned implements DomainEventInterface
{
    public function __construct(
        public string $userId,
        public string $attemptId,
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
