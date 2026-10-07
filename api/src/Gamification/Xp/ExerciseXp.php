<?php

declare(strict_types=1);

namespace App\Gamification\Xp;

use App\Activity\Event\ExerciseCompleted;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\SendMessageToTransportsEvent;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The XP that the exercises completed by the current request will gain (docs/GAMIFICATION.md,
 * "XP d'un exercice dans la réponse"), for the end-of-exercise animation. The ledger is written
 * later by the outbox's handler; this previews it when an ExerciseCompleted enters the outbox,
 * with the same rule and the same daily cap, so the domains publishing exercises know nothing of
 * it. Scoped to one request (reset by the kernel between requests and between worker messages).
 */
final class ExerciseXp implements EventSubscriberInterface, ResetInterface
{
    private ?int $gained = null;

    public function __construct(
        private readonly XpLedger $ledger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [SendMessageToTransportsEvent::class => 'onSend'];
    }

    public function onSend(SendMessageToTransportsEvent $event): void
    {
        $envelope = $event->getEnvelope();
        $message = $envelope->getMessage();
        // A retry re-sends the event from the worker: nothing to preview there.
        if (!$message instanceof ExerciseCompleted || [] !== $envelope->all(RedeliveryStamp::class)) {
            return;
        }
        $pending = $this->gained ?? 0;
        $this->gained = $pending + $this->ledger->preview($message, $pending);
    }

    /**
     * XP of the exercises completed since the last reset, null when none was.
     */
    public function gained(): ?int
    {
        return $this->gained;
    }

    /**
     * Forgets what came before, e.g. an expired run closed at the start of a submission.
     */
    public function reset(): void
    {
        $this->gained = null;
    }
}
