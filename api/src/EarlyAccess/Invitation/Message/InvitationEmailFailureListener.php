<?php

declare(strict_types=1);

namespace App\EarlyAccess\Invitation\Message;

use App\Entity\EarlyAccess\InvitationLog;
use App\Enum\EarlyAccess\InvitationAction;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;

/**
 * Once Messenger gives up on an invitation email (retries spent, the message goes to the failure
 * transport), the invitation shows "failed" so the admin can resend it.
 */
#[AsEventListener]
final readonly class InvitationEmailFailureListener
{
    public function __construct(
        private SendInvitationEmailHandler $handler,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(WorkerMessageFailedEvent $event): void
    {
        $message = $event->getEnvelope()->getMessage();
        if ($event->willRetry() || !$message instanceof SendInvitationEmail) {
            return;
        }
        $invitation = $this->handler->current($message);
        if (null === $invitation) {
            return;
        }
        $invitation->markSendFailed();
        $this->entityManager->persist(new InvitationLog($invitation, InvitationAction::KeySendFailed, null, $this->clock->now(), [
            'keyHint' => $invitation->getKeyHint(),
            'error' => $event->getThrowable()::class,
        ]));
        $this->entityManager->flush();
    }
}
