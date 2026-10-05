<?php

declare(strict_types=1);

namespace App\EarlyAccess\Invitation;

use App\Entity\EarlyAccess\InvitationKey;
use App\Entity\EarlyAccess\InvitationLog;
use App\Entity\User;
use App\Enum\EarlyAccess\InvitationAction;
use App\Enum\EarlyAccess\InvitationStatus;
use App\Repository\EarlyAccess\InvitationKeyRepository;
use App\Security\Registration\RegistrationGateInterface;
use App\Security\Registration\RegistrationRefusedException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * The sign-up side of invitations (docs/EARLY_ACCESS.md): a new account needs a pending key, and
 * spends it. The ticket handed to the auth side is the key's sha256, so an OAuth flow can carry it
 * without storing the key, and a resend in the meantime (a new key) invalidates it.
 */
final readonly class InvitationRedeemer implements RegistrationGateInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private InvitationKeyRepository $invitations,
        private ClockInterface $clock,
    ) {
    }

    /**
     * The usable invitation of a key, without logging anything (the sign-up page checks its link).
     *
     * @throws RegistrationRefusedException
     */
    public function inspect(#[\SensitiveParameter] ?string $key): InvitationKey
    {
        if (null === $key || '' === $key) {
            throw new RegistrationRefusedException(RegistrationRefusedException::REQUIRED);
        }
        $invitation = KeyGenerator::isWellFormed($key) ? $this->invitations->findByKeyHash(KeyGenerator::hash($key)) : null;
        $status = $invitation?->getStatus($this->clock->now());
        if (null === $invitation || InvitationStatus::Pending !== $status) {
            throw new RegistrationRefusedException(InvitationStatus::Expired === $status ? RegistrationRefusedException::EXPIRED : RegistrationRefusedException::INVALID);
        }

        return $invitation;
    }

    /**
     * A sign-up attempt with an expired key is logged (expiry itself needs no cron).
     */
    public function admit(#[\SensitiveParameter] ?string $key): string
    {
        try {
            return $this->inspect($key)->getKeyHash();
        } catch (RegistrationRefusedException $refusal) {
            if (RegistrationRefusedException::EXPIRED === $refusal->reason && null !== $key) {
                $this->logExpired($key);
            }

            throw $refusal;
        }
    }

    /**
     * Locks the invitation row (SELECT ... FOR UPDATE, hence the transaction the caller runs) and
     * re-reads it: a concurrent sign-up with the same key waits, then finds it used.
     */
    public function redeem(string $ticket, ?User $user, string $method): void
    {
        $invitation = $this->invitations->findByKeyHash($ticket)
            ?? throw new RegistrationRefusedException(RegistrationRefusedException::INVALID);
        $this->entityManager->refresh($invitation, LockMode::PESSIMISTIC_WRITE);

        $now = $this->clock->now();
        $status = $invitation->getStatus($now);
        if (!hash_equals($invitation->getKeyHash(), $ticket) || InvitationStatus::Pending !== $status) {
            throw new RegistrationRefusedException(InvitationStatus::Expired === $status ? RegistrationRefusedException::EXPIRED : RegistrationRefusedException::INVALID);
        }

        $invitation->markUsed($now, $user);
        $this->entityManager->persist(new InvitationLog($invitation, InvitationAction::KeyUsed, $user, $now, [
            'keyHint' => $invitation->getKeyHint(),
            'method' => $method,
            'accountCreated' => null !== $user,
        ]));
    }

    private function logExpired(#[\SensitiveParameter] string $key): void
    {
        $invitation = $this->invitations->findByKeyHash(KeyGenerator::hash($key));
        if (null === $invitation) {
            return;
        }
        $this->entityManager->persist(new InvitationLog($invitation, InvitationAction::KeyExpired, null, $this->clock->now(), [
            'keyHint' => $invitation->getKeyHint(),
        ]));
        $this->entityManager->flush();
    }
}
