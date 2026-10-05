<?php

declare(strict_types=1);

namespace App\Ops\Queue;

use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;

/**
 * Stops a drain ({@see QueueDrainer}) as soon as its queues are empty, or once it has handled its
 * share of messages; counts what it handled. A plain worker would wait for more instead.
 *
 * Built for each drain, never a service: autoconfigured, it would listen to every worker.
 *
 * @internal
 */
#[Exclude]
final class DrainControl implements EventSubscriberInterface
{
    public int $handled = 0;
    public int $failed = 0;

    public function __construct(private readonly int $maxMessages)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            WorkerMessageHandledEvent::class => 'onHandled',
            WorkerMessageFailedEvent::class => 'onFailed',
            WorkerRunningEvent::class => 'onRunning',
        ];
    }

    public function onHandled(): void
    {
        ++$this->handled;
    }

    public function onFailed(): void
    {
        ++$this->failed;
    }

    public function onRunning(WorkerRunningEvent $event): void
    {
        if ($event->isWorkerIdle() || $this->handled + $this->failed >= $this->maxMessages) {
            $event->getWorker()->stop();
        }
    }
}
