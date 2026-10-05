<?php

declare(strict_types=1);

namespace App\Ops\Queue;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;
use Symfony\Component\Messenger\Worker;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Consumes Messenger queues for a bounded moment, inside the current PHP process: what a worker
 * does, for a host that runs no long-lived process (docs/DEPLOY_OVH.md). Same machinery as
 * `messenger:consume`: the application's event dispatcher (retries, failure transport, the
 * failure listeners of the domains) and services reset between messages.
 *
 * It stops at the first of: the queues are empty, `$maxMessages` handled, `$seconds` elapsed.
 * Messages are only ever acknowledged once handled: an interrupted drain loses nothing.
 */
final readonly class QueueDrainer
{
    public function __construct(
        #[Autowire(service: 'messenger.receiver_locator')]
        private ContainerInterface $receivers,
        #[Autowire(service: 'messenger.routable_message_bus')]
        private MessageBusInterface $bus,
        private EventDispatcherInterface $dispatcher,
        #[Autowire(service: 'messenger.listener.reset_services')]
        private EventSubscriberInterface $resetServices,
        #[Autowire(service: 'services_resetter')]
        private ResetInterface $servicesResetter,
        private ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * @param list<string> $transports in order of priority (each round reads the first non-empty one)
     * @param bool         $resetFirst start from fresh services (after a request, whose entity
     *                                 manager may be dirty or closed)
     *
     * @return array{handled: int, failed: int}
     */
    public function drain(array $transports, int $seconds, int $maxMessages, bool $resetFirst = false): array
    {
        if ($resetFirst) {
            $this->servicesResetter->reset();
        }
        $receivers = [];
        foreach ($transports as $name) {
            $receiver = $this->receivers->get($name);
            if (!$receiver instanceof ReceiverInterface) {
                throw new \LogicException(sprintf('No receiver for the "%s" transport.', $name));
            }
            $receivers[$name] = $receiver;
        }

        $control = new DrainControl($maxMessages);
        $subscribers = [$control, $this->resetServices];
        foreach ($subscribers as $subscriber) {
            $this->dispatcher->addSubscriber($subscriber);
        }
        try {
            (new Worker($receivers, $this->bus, $this->dispatcher, $this->logger))->run([
                // Never waits for new messages: an idle round stops the drain (DrainControl).
                'sleep' => 0,
                'time_limit' => $seconds,
            ]);
        } finally {
            foreach ($subscribers as $subscriber) {
                $this->dispatcher->removeSubscriber($subscriber);
            }
        }

        return ['handled' => $control->handled, 'failed' => $control->failed];
    }
}
