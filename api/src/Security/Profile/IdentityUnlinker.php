<?php

declare(strict_types=1);

namespace App\Security\Profile;

use App\Entity\AuthIdentity;
use App\Entity\User;
use App\Enum\AuditEventType;
use App\Security\Audit\AuditLogger;
use App\Security\OAuth\OAuthTokenVault;
use App\Security\RefreshToken\RefreshTokenService;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Removes a linked OAuth identity from its owner's account.
 *
 * - Never the last way to sign in (checked under a row lock on the user, so two concurrent unlinks
 *   can't both pass the check).
 * - The provider token we kept, if any, is revoked at the provider.
 * - Every session is ended, since some may have been opened through that identity (e.g. by
 *   whoever linked their own account after stealing an access token); the caller then gets a new
 *   one, as with a password change.
 */
final readonly class IdentityUnlinker
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private OAuthTokenVault $tokenVault,
        private RefreshTokenService $refreshTokenService,
        private AuditLogger $auditLogger,
    ) {
    }

    /**
     * @return User the user, reloaded (the session revocation clears the entity manager)
     *
     * @throws \OutOfBoundsException if the user has no such identity
     * @throws \DomainException      if it is the account's last way to sign in
     */
    public function unlink(User $user, Uuid $identityId): User
    {
        $provider = $this->entityManager->wrapInTransaction(function (EntityManagerInterface $entityManager) use ($user, $identityId) {
            $locked = $entityManager->find(User::class, $user->getId(), LockMode::PESSIMISTIC_WRITE)
                ?? throw new \OutOfBoundsException('Unknown user.');
            $entityManager->refresh($locked);

            $identity = $locked->getAuthIdentities()->findFirst(
                static fn (int $key, AuthIdentity $candidate): bool => $candidate->getId()->equals($identityId),
            ) ?? throw new \OutOfBoundsException('Unknown identity.');

            if ($locked->countAuthMethods() <= 1) {
                throw new \DomainException('This is the last way to sign into the account.');
            }

            $this->tokenVault->revoke($identity);
            $locked->getAuthIdentities()->removeElement($identity);
            $locked->bumpTokenVersion();

            return $identity->getProvider();
        });

        $this->auditLogger->log(AuditEventType::AccountUnlinked, $user, ['provider' => $provider->value]);

        // Last: the bulk revocation clears the entity manager.
        $this->refreshTokenService->revokeAllSessions($user);

        return $this->entityManager->find(User::class, $user->getId()) ?? throw new \OutOfBoundsException('Unknown user.');
    }
}
