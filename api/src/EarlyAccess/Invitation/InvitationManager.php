<?php

declare(strict_types=1);

namespace App\EarlyAccess\Invitation;

use App\EarlyAccess\Invitation\Message\SendInvitationEmail;
use App\Entity\EarlyAccess\AccessRequest;
use App\Entity\EarlyAccess\InvitationKey;
use App\Entity\EarlyAccess\InvitationLog;
use App\Entity\User;
use App\Enum\EarlyAccess\InvitationAction;
use App\Enum\EarlyAccess\InvitationStatus;
use App\Repository\EarlyAccess\AccessRequestRepository;
use App\Repository\EarlyAccess\InvitationKeyRepository;
use App\Security\Crypto\SecretBox;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

/**
 * The admin side of invitations (docs/EARLY_ACCESS.md): create (from scratch or from a waiting-list
 * request), resend (a new key), revoke. Each change, its log line and its queued email are written
 * in one transaction: the async transport is a table of the same database, so an email is only
 * ever queued for a committed key.
 *
 * Refusals are checked before the first write ({@see InvitationException}): a transaction closes
 * the entity manager on any exception.
 */
final readonly class InvitationManager
{
    /** The furthest expiry an admin can set. */
    public const MAX_LIFETIME = '+1 year';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private InvitationKeyRepository $invitations,
        private AccessRequestRepository $requests,
        private KeyGenerator $keyGenerator,
        private SecretBox $secretBox,
        private MessageBusInterface $bus,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param \DateTimeImmutable|null $expiresAt null: the default lifetime, unless $neverExpires
     *
     * @return array{InvitationKey, string} the invitation and its key, shown to the admin once
     *
     * @throws InvitationException
     */
    public function create(User $admin, string $email, ?\DateTimeImmutable $expiresAt, bool $neverExpires = false): array
    {
        $now = $this->clock->now();
        $expiresAt = $this->expiry($now, $expiresAt, $neverExpires);
        $key = $this->keyGenerator->generate();
        $invitation = new InvitationKey(mb_strtolower(trim($email)), KeyGenerator::hash($key), KeyGenerator::hint($key), $admin, $now, $expiresAt);

        $this->entityManager->wrapInTransaction(function () use ($invitation, $admin, $now, $key): void {
            $this->persistNew($invitation, $admin, $now, $key);
        });

        return [$invitation, $key];
    }

    /**
     * Invites the address of a waiting-list request (default lifetime). The request is claimed in
     * the same transaction, so two admins clicking at once create a single invitation.
     *
     * @return array{InvitationKey, string}
     *
     * @throws InvitationException
     */
    public function createFromRequest(User $admin, AccessRequest $request): array
    {
        if (null !== $request->getInvitedAt()) {
            throw new InvitationException(InvitationException::ALREADY_INVITED, 'This request was already invited.');
        }
        $now = $this->clock->now();
        $key = $this->keyGenerator->generate();
        $invitation = new InvitationKey($request->getEmail(), KeyGenerator::hash($key), KeyGenerator::hint($key), $admin, $now, $now->modify(InvitationKey::DEFAULT_LIFETIME));

        // The refusal is returned, not thrown: a transaction closes the entity manager on any exception.
        $claimed = $this->entityManager->wrapInTransaction(function () use ($invitation, $admin, $now, $key, $request): bool {
            if (!$this->requests->claim($request->getId(), $now)) {
                return false;
            }
            $request->markInvited($invitation, $now);
            $this->persistNew($invitation, $admin, $now, $key, ['fromRequest' => true]);

            return true;
        });
        if (!$claimed) {
            throw new InvitationException(InvitationException::ALREADY_INVITED, 'This request was already invited.');
        }

        return [$invitation, $key];
    }

    /**
     * A new key on the invitation, emailed again; the previous key stops working. An expired
     * invitation gets the default lifetime again.
     *
     * @return array{InvitationKey, string}
     *
     * @throws InvitationException
     */
    public function resend(User $admin, Uuid $id): array
    {
        $invitation = $this->find($id);
        $now = $this->clock->now();
        $status = $invitation->getStatus($now);
        if (InvitationStatus::Used === $status || InvitationStatus::Revoked === $status) {
            throw new InvitationException(InvitationException::CLOSED, 'This invitation was used or revoked.');
        }
        $expiresAt = InvitationStatus::Expired === $status ? $now->modify(InvitationKey::DEFAULT_LIFETIME) : $invitation->getExpiresAt();
        $key = $this->keyGenerator->generate();

        $this->entityManager->wrapInTransaction(function () use ($invitation, $admin, $now, $key, $expiresAt): void {
            $invitation->renewKey(KeyGenerator::hash($key), KeyGenerator::hint($key), $expiresAt);
            $this->entityManager->persist(new InvitationLog($invitation, InvitationAction::KeyResent, $admin, $now, [
                'keyHint' => $invitation->getKeyHint(),
                'expiresAt' => $expiresAt?->format(\DATE_ATOM),
            ]));
            $this->entityManager->flush();
            $this->queueEmail($invitation, $key);
        });

        return [$invitation, $key];
    }

    /**
     * Idempotent. A used invitation cannot be revoked (the account exists).
     *
     * @throws InvitationException
     */
    public function revoke(User $admin, Uuid $id): InvitationKey
    {
        $invitation = $this->find($id);
        if (null !== $invitation->getUsedAt()) {
            throw new InvitationException(InvitationException::CLOSED, 'This invitation was already used.');
        }
        if (null === $invitation->getRevokedAt()) {
            $now = $this->clock->now();
            $invitation->revoke($now);
            $this->entityManager->persist(new InvitationLog($invitation, InvitationAction::KeyRevoked, $admin, $now));
            $this->entityManager->flush();
        }

        return $invitation;
    }

    /**
     * @throws InvitationException
     */
    public function find(Uuid $id): InvitationKey
    {
        return $this->invitations->find($id)
            ?? throw new InvitationException(InvitationException::NOT_FOUND, 'Invitation not found.');
    }

    /**
     * @throws InvitationException
     */
    private function expiry(\DateTimeImmutable $now, ?\DateTimeImmutable $expiresAt, bool $neverExpires): ?\DateTimeImmutable
    {
        if ($neverExpires) {
            return null;
        }
        if (null === $expiresAt) {
            return $now->modify(InvitationKey::DEFAULT_LIFETIME);
        }
        if ($expiresAt <= $now) {
            throw new InvitationException(InvitationException::INVALID_EXPIRY, 'The expiry date is in the past.');
        }
        if ($expiresAt > $now->modify(self::MAX_LIFETIME)) {
            throw new InvitationException(InvitationException::INVALID_EXPIRY, 'The expiry date is more than a year away.');
        }

        return $expiresAt->setTimezone(new \DateTimeZone('UTC'));
    }

    /**
     * Inside a transaction: the new invitation, its log line and its email.
     *
     * @param array<string, mixed> $details added to the log line
     */
    private function persistNew(InvitationKey $invitation, User $admin, \DateTimeImmutable $now, #[\SensitiveParameter] string $key, array $details = []): void
    {
        $this->entityManager->persist($invitation);
        $this->entityManager->persist(new InvitationLog($invitation, InvitationAction::KeyCreated, $admin, $now, [
            'keyHint' => $invitation->getKeyHint(),
            'expiresAt' => $invitation->getExpiresAt()?->format(\DATE_ATOM),
        ] + $details));
        $this->entityManager->flush();
        $this->queueEmail($invitation, $key);
    }

    private function queueEmail(InvitationKey $invitation, #[\SensitiveParameter] string $key): void
    {
        $this->bus->dispatch(new SendInvitationEmail($invitation->getId()->toRfc4122(), $this->secretBox->encrypt($key)));
    }
}
