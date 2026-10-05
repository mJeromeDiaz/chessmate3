<?php

declare(strict_types=1);

namespace App\EarlyAccess\Player;

use App\Entity\User;
use App\Enum\AuditEventType;
use App\Repository\MfaChallengeRepository;
use App\Repository\UserRepository;
use App\Security\Audit\AuditLogger;
use App\Security\RefreshToken\RefreshTokenService;
use App\Security\TrustedDevice\TrustedDeviceService;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Suspends an account, or lifts its suspension (docs/EARLY_ACCESS.md). Suspending closes everything
 * at once: the token version is bumped (access tokens already issued stop working), and every
 * session, trusted device and pending email code is revoked. Sign-ins are then refused where every
 * session starts ({@see \App\Security\Session\AuthenticatedSessionFactory}). Lifting it restores
 * nothing but the right to sign in again: the player starts a new session.
 *
 * Both are idempotent; suspending again only updates the reason.
 */
final readonly class Suspension
{
    public function __construct(
        private UserRepository $users,
        private RefreshTokenService $refreshTokens,
        private TrustedDeviceService $trustedDevices,
        private MfaChallengeRepository $mfaChallenges,
        private AuditLogger $auditLogger,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return User the suspended account (reloaded: the bulk revocation clears the entity manager)
     *
     * @throws SuspensionException
     */
    public function suspend(User $admin, Uuid $id, ?string $reason): User
    {
        $user = $this->find($id);
        if ($user->getId()->equals($admin->getId())) {
            throw new SuspensionException(SuspensionException::SELF, 'You cannot suspend your own account.');
        }
        if (\in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            throw new SuspensionException(SuspensionException::ADMIN, 'Take the admin role back before suspending this account.');
        }
        $reason = null === $reason || '' === trim($reason) ? null : trim($reason);
        $wasSuspended = $user->isSuspended();

        $user->suspend($this->clock->now(), $reason);
        if (!$wasSuspended) {
            $user->bumpTokenVersion();
        }
        $this->users->save($user);
        $this->auditLogger->log(AuditEventType::AccountSuspended, $user, ['admin_id' => $admin->getId()->toRfc4122(), 'already_suspended' => $wasSuspended]);

        if (!$wasSuspended) {
            $this->trustedDevices->revokeAllForUser($user);
            $this->mfaChallenges->invalidateActiveForUser($user);
            // Last: the bulk revocation clears the entity manager, detaching $user.
            $this->refreshTokens->revokeAllSessions($user);
        }

        return $this->find($id);
    }

    /**
     * @throws SuspensionException
     */
    public function lift(User $admin, Uuid $id): User
    {
        $user = $this->find($id);
        if ($user->isSuspended()) {
            $user->liftSuspension();
            $this->users->save($user);
            $this->auditLogger->log(AuditEventType::AccountUnsuspended, $user, ['admin_id' => $admin->getId()->toRfc4122()]);
        }

        return $user;
    }

    /**
     * @throws SuspensionException
     */
    private function find(Uuid $id): User
    {
        return $this->users->findOneByUuid($id)
            ?? throw new SuspensionException(SuspensionException::NOT_FOUND, 'Account not found.');
    }
}
