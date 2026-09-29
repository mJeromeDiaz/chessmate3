<?php

declare(strict_types=1);

namespace App\Activity\Handler;

use App\Activity\Event\DomainEventInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Gives every domain event at least one handler: Messenger rejects a message without one, and the
 * worker would retry it, then move it to the "failed" transport. Events nothing reacts to yet
 * (RunCompleted, SetGrown…) are thus simply consumed; real subscribers are added next to it.
 */
#[AsMessageHandler]
final class AcknowledgeDomainEvent
{
    public function __invoke(DomainEventInterface $event): void
    {
    }
}
