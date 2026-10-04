<?php

declare(strict_types=1);

namespace App\Tests\Functional\Activity;

use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * Reads and runs the outbox (the Messenger "activity" Doctrine transport) the way the worker does.
 */
trait ActivityOutboxTrait
{
    protected function outbox(): TransportInterface&MessageCountAwareInterface
    {
        /** @var TransportInterface&MessageCountAwareInterface */
        return self::getContainer()->get('messenger.transport.activity');
    }

    /**
     * @return int messages handled
     */
    protected function runOutbox(): int
    {
        $bus = self::getContainer()->get(MessageBusInterface::class);
        $handled = 0;
        do {
            $envelopes = [...$this->outbox()->get()];
            foreach ($envelopes as $envelope) {
                $bus->dispatch($envelope->with(new ReceivedStamp('activity')));
                $this->outbox()->ack($envelope);
                ++$handled;
            }
        } while ([] !== $envelopes);

        return $handled;
    }

    /**
     * @return list<object> the messages of the outbox, which is emptied without handling them
     */
    protected function takeOutboxMessages(): array
    {
        $messages = [];
        do {
            $batch = [...$this->outbox()->get()];
            foreach ($batch as $envelope) {
                $messages[] = $envelope->getMessage();
                $this->outbox()->ack($envelope);
            }
        } while ([] !== $batch);

        return $messages;
    }
}
