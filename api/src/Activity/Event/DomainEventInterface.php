<?php

declare(strict_types=1);

namespace App\Activity\Event;

/**
 * A domain event published through the transactional outbox (Messenger "activity" transport,
 * docs/ACTIVITY.md). Implementations are immutable, carry scalars only (never entities) and are
 * part of a stable contract: add fields, never rename or remove them.
 */
interface DomainEventInterface
{
    public function getUserId(): string;

    public function getOccurredAt(): \DateTimeImmutable;
}
