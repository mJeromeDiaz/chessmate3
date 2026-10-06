<?php

declare(strict_types=1);

namespace App\EarlyAccess\Invitation\Message;

use App\EarlyAccess\Invitation\KeyGenerator;
use App\Entity\EarlyAccess\InvitationKey;
use App\Entity\EarlyAccess\InvitationLog;
use App\Enum\EarlyAccess\InvitationAction;
use App\Enum\EarlyAccess\InvitationStatus;
use App\Mailer\SyncMailer;
use App\Repository\EarlyAccess\InvitationKeyRepository;
use App\Security\Crypto\SecretBox;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Address;
use Symfony\Component\Uid\Uuid;

/**
 * Emails an invitation's key, then marks it sent. The email goes straight to the mailer transport
 * ({@see SyncMailer}): a delivery failure throws here, so Messenger retries this message, and
 * {@see InvitationEmailFailureListener} marks the invitation failed once the retries are spent.
 *
 * A stale message is dropped: its key was replaced by a resend, or the invitation was used or
 * revoked meanwhile.
 */
#[AsMessageHandler]
final readonly class SendInvitationEmailHandler
{
    public function __construct(
        private InvitationKeyRepository $invitations,
        private EntityManagerInterface $entityManager,
        private SecretBox $secretBox,
        private SyncMailer $mailer,
        private ClockInterface $clock,
        #[Autowire('%env(MAILER_FROM_ADDRESS)%')]
        private string $fromAddress,
        #[Autowire('%env(FRONTEND_URL)%')]
        private string $frontendUrl,
    ) {
    }

    public function __invoke(SendInvitationEmail $message): void
    {
        $invitation = $this->current($message);
        if (null === $invitation) {
            return;
        }
        $key = $this->secretBox->decrypt($message->encryptedKey);
        $now = $this->clock->now();
        if (InvitationStatus::Pending !== $invitation->getStatus($now)) {
            return;
        }

        $timezone = $invitation->getCreatedBy()?->getDateTimeZone() ?? new \DateTimeZone('UTC');
        $expiresAt = $invitation->getExpiresAt()?->setTimezone($timezone);
        $this->mailer->send((new TemplatedEmail())
            ->from(new Address($this->fromAddress, 'Don\'t Stay Rooky'))
            ->to($invitation->getEmail())
            ->subject('Ton invitation à l\'accès anticipé de Don\'t Stay Rooky')
            ->htmlTemplate('emails/early_access_invitation.html.twig')
            ->textTemplate('emails/early_access_invitation.txt.twig')
            ->context([
                'url' => $this->frontendUrl.'/#/register?key='.$key,
                'expiresAt' => null === $expiresAt ? null : \sprintf('%s à %s (%s)', $expiresAt->format('d/m/Y'), $expiresAt->format('H:i'), $timezone->getName()),
            ]));

        $invitation->markSent($now);
        $this->entityManager->persist(new InvitationLog($invitation, InvitationAction::KeySent, null, $now, [
            'keyHint' => $invitation->getKeyHint(),
        ]));
        $this->entityManager->flush();
    }

    /**
     * The invitation of the message, if the message's key is still its key.
     */
    public function current(SendInvitationEmail $message): ?InvitationKey
    {
        if (!Uuid::isValid($message->invitationId)) {
            return null;
        }
        $invitation = $this->invitations->find(Uuid::fromString($message->invitationId));
        if (null === $invitation) {
            return null;
        }
        $hash = KeyGenerator::hash($this->secretBox->decrypt($message->encryptedKey));

        return hash_equals($invitation->getKeyHash(), $hash) ? $invitation : null;
    }
}
