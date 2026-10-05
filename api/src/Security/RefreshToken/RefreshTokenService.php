<?php

declare(strict_types=1);

namespace App\Security\RefreshToken;

use App\Entity\RefreshToken;
use App\Entity\User;
use App\Enum\AuditEventType;
use App\Repository\RefreshTokenRepository;
use App\Repository\UserRepository;
use App\Security\Audit\AuditLogger;
use App\Security\RefreshToken\Exception\RefreshTokenExpiredException;
use App\Security\RefreshToken\Exception\RefreshTokenNotFoundException;
use App\Security\RefreshToken\Exception\RefreshTokenReuseDetectedException;
use Gesdinet\JWTRefreshTokenBundle\Generator\RefreshTokenGeneratorInterface;
use Gesdinet\JWTRefreshTokenBundle\Model\RefreshTokenManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Uid\Uuid;

/**
 * Issues and rotates refresh tokens outside of gesdinet's own Security-authenticator flow.
 *
 * See {@see RefreshToken} for why: this is what makes reuse detection possible.
 */
final readonly class RefreshTokenService
{
    public function __construct(
        private RefreshTokenGeneratorInterface $generator,
        private RefreshTokenManagerInterface $manager,
        private RefreshTokenRepository $repository,
        private UserRepository $userRepository,
        private AuditLogger $auditLogger,
        private RequestStack $requestStack,
        #[Autowire('%env(int:REFRESH_TOKEN_IDLE_TTL)%')]
        private int $idleTtl,
        #[Autowire('%env(int:REFRESH_TOKEN_ABSOLUTE_TTL)%')]
        private int $absoluteTtl,
    ) {
    }

    /**
     * Issues the first token of a brand new family, for a fresh login. The family's absolute
     * lifetime starts now.
     */
    public function issueNewFamily(User $user): IssuedRefreshToken
    {
        $familyExpiresAt = new \DateTimeImmutable(sprintf('+%d seconds', $this->absoluteTtl));

        return $this->issue($user, Uuid::v7(), $familyExpiresAt, new \DateTimeImmutable()) ?? throw new \LogicException('A new family cannot already be expired.');
    }

    /**
     * Validates a presented token and, if it is genuinely still active, rotates it: the old row is
     * revoked and a new token in the same family is returned. Any other outcome — not found,
     * expired, or already revoked — throws instead of returning a token.
     *
     * @throws RefreshTokenNotFoundException
     * @throws RefreshTokenExpiredException
     * @throws RefreshTokenReuseDetectedException
     */
    public function rotate(string $presentedPlainToken): RotationResult
    {
        $refreshToken = $this->manager->get($presentedPlainToken);

        if (!$refreshToken instanceof RefreshToken) {
            throw new RefreshTokenNotFoundException();
        }

        if ($refreshToken->isRevoked()) {
            $this->revokeFamilyAndAudit($refreshToken, 'already_revoked');

            throw new RefreshTokenReuseDetectedException();
        }

        if (!$refreshToken->isValid()) {
            throw new RefreshTokenExpiredException();
        }

        if (0 === $this->repository->revokeIfActive($refreshToken)) {
            // Raced with another rotation of the very same token between the checks above and here.
            $this->revokeFamilyAndAudit($refreshToken, 'concurrent_rotation');

            throw new RefreshTokenReuseDetectedException();
        }

        $user = $this->loadUser($refreshToken);

        // A suspension revokes every session; this only closes the race with one in flight.
        if (null === $user || $user->isSuspended()) {
            throw new RefreshTokenNotFoundException();
        }

        $replacement = $this->issue($user, $refreshToken->getFamilyId(), $refreshToken->getFamilyExpiresAt(), $refreshToken->getSignedInAt());

        if (null === $replacement) {
            throw new RefreshTokenExpiredException();
        }

        return new RotationResult($user, $replacement);
    }

    /**
     * Revokes the session (the whole family) a presented token belongs to — logout. Silent no-op
     * for a token that doesn't resolve to anything, since logout should never fail visibly.
     */
    public function revokeSession(string $presentedPlainToken): void
    {
        $refreshToken = $this->manager->get($presentedPlainToken);

        if (!$refreshToken instanceof RefreshToken || $refreshToken->isRevoked()) {
            return;
        }

        $this->repository->revokeFamily($refreshToken->getFamilyId());

        $this->auditLogger->log(AuditEventType::Logout, $this->loadUser($refreshToken));
    }

    /**
     * The family (session) of a presented token, if it is still active: tells the profile which of
     * the listed sessions is the one asking.
     */
    public function activeFamilyOf(string $presentedPlainToken): ?Uuid
    {
        $refreshToken = $this->manager->get($presentedPlainToken);

        return $refreshToken instanceof RefreshToken && !$refreshToken->isRevoked() && $refreshToken->isValid()
            ? $refreshToken->getFamilyId()
            : null;
    }

    /**
     * When the presented session signed in, if it is still active and belongs to $user: proves a
     * recent sign-in (account deletion without an email, docs/AUTH.md).
     */
    public function signedInAtOf(User $user, string $presentedPlainToken): ?\DateTimeImmutable
    {
        $refreshToken = $this->manager->get($presentedPlainToken);

        return $refreshToken instanceof RefreshToken && !$refreshToken->isRevoked() && $refreshToken->isValid()
            && $refreshToken->getUsername() === $user->getUserIdentifier()
            ? $refreshToken->getSignedInAt()
            : null;
    }

    /**
     * Closes one of the user's sessions from another one (profile). Its access tokens die at once:
     * the token version is bumped, the user's other sessions just refresh transparently.
     *
     * @return bool false if no active session of this user has this family
     */
    public function revokeFamilyOf(User $user, Uuid $familyId): bool
    {
        if (0 === $this->repository->revokeFamilyOfUser($familyId, $user->getUserIdentifier())) {
            return false;
        }

        $user = $this->userRepository->findOneByUuid($user->getId());
        if (null !== $user) {
            $this->userRepository->save($user->bumpTokenVersion());
        }
        $this->auditLogger->log(AuditEventType::SessionRevoked, $user, ['family' => $familyId->toRfc4122()]);

        return true;
    }

    /**
     * Revokes every session of a user, on every device — e.g. after a password change.
     */
    public function revokeAllSessions(User $user): void
    {
        $this->repository->revokeAllForUser($user->getUserIdentifier());
    }

    /**
     * @return IssuedRefreshToken|null null if the family has already reached its absolute expiry
     */
    private function issue(User $user, Uuid $familyId, \DateTimeImmutable $familyExpiresAt, ?\DateTimeImmutable $signedInAt): ?IssuedRefreshToken
    {
        // Idle timeout, but never past the family's absolute expiry.
        $ttl = min($this->idleTtl, $familyExpiresAt->getTimestamp() - time());

        if ($ttl <= 0) {
            return null;
        }

        $refreshToken = $this->generator->createForUserWithTtl($user, $ttl);

        if (!$refreshToken instanceof RefreshToken) {
            throw new \LogicException(sprintf('Expected an instance of "%s".', RefreshToken::class));
        }

        // Must be read before save(): the hashing manager overwrites this field with the hash.
        $plainToken = (string) $refreshToken->getRefreshToken();
        $refreshToken->setFamilyId($familyId);
        $refreshToken->setFamilyExpiresAt($familyExpiresAt);
        // Null outside an HTTP request (console commands such as the e2e seed).
        $request = $this->requestStack->getCurrentRequest();
        $refreshToken->setOrigin($signedInAt, new \DateTimeImmutable(), $request?->headers->get('User-Agent'), $request?->getClientIp());

        $this->manager->save($refreshToken);

        return new IssuedRefreshToken($plainToken, \DateTimeImmutable::createFromInterface($refreshToken->getValid() ?? new \DateTimeImmutable()));
    }

    private function loadUser(RefreshToken $refreshToken): ?User
    {
        $identifier = $refreshToken->getUsername();

        if (null === $identifier || !Uuid::isValid($identifier)) {
            return null;
        }

        return $this->userRepository->findOneByUuid(Uuid::fromString($identifier));
    }

    /**
     * A reused token means someone other than the legitimate client may hold this family. Besides
     * revoking it, the user's token version is bumped so that any access token already minted from
     * it stops working right away; the user's other sessions just refresh transparently.
     */
    private function revokeFamilyAndAudit(RefreshToken $refreshToken, string $reason): void
    {
        $this->repository->revokeFamily($refreshToken->getFamilyId());

        $user = $this->loadUser($refreshToken);

        if (null !== $user) {
            $this->userRepository->save($user->bumpTokenVersion());
        }

        $this->auditLogger->log(AuditEventType::RefreshTokenReuseDetected, $user, ['reason' => $reason]);
    }
}
