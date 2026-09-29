<?php

declare(strict_types=1);

namespace App\Activity;

use App\Activity\Event\DomainEventInterface;
use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The only way to publish a domain event. It must be called inside the database transaction of
 * the change it describes: the event is written to the outbox (messenger_messages) by that same
 * transaction, so it exists if and only if the change committed.
 */
final class EventPublisher
{
    public function __construct(
        private readonly MessageBusInterface $bus,
        private readonly Connection $connection,
    ) {
    }

    public function publish(DomainEventInterface $event): void
    {
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('Domain events are published inside the transaction of the change they describe.');
        }

        $this->bus->dispatch($event);
    }
}
