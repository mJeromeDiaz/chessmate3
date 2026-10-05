<?php

declare(strict_types=1);

namespace App\Ops\Queue;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * After a write request to the API, once the response is sent (PHP-FPM: fastcgi_finish_request),
 * handles a few domain events of the `activity` queue: a player's XP, streak and activity are up to
 * date by their next page, without waiting for the minute's tick (docs/DEPLOY_OVH.md).
 *
 * Off unless OPS_DRAIN_ON_TERMINATE (production on shared hosting); where a worker runs (dev, test,
 * e2e, a VPS), it stays off. A failure here is logged and never affects the request.
 */
#[AsEventListener(event: KernelEvents::TERMINATE, priority: -2048)]
final readonly class DrainOnTerminateListener
{
    public const SECONDS = 2;
    public const MAX_MESSAGES = 20;

    public function __construct(
        private QueueDrainer $drainer,
        #[Autowire('%env(bool:OPS_DRAIN_ON_TERMINATE)%')]
        private bool $enabled,
        private ?LoggerInterface $logger = null,
    ) {
    }

    public function __invoke(TerminateEvent $event): void
    {
        $request = $event->getRequest();
        if (!$this->enabled
            || !$event->isMainRequest()
            || $request->isMethodSafe()
            || !str_starts_with($request->getPathInfo(), '/api/')
            || 'app_ops_tick' === $request->attributes->get('_route')
        ) {
            return;
        }

        try {
            $this->drainer->drain(['activity'], self::SECONDS, self::MAX_MESSAGES, resetFirst: true);
        } catch (\Throwable $exception) {
            $this->logger?->warning('Draining the activity queue after a request failed.', ['exception' => $exception]);
        }
    }
}
