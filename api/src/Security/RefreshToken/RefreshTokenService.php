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

        return $this->issue($user, Uuid::v7(), $familyExpiresAt) ?? throw new \LogicException('A new family cannot already be expired.');
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

        if (null === $user) {
            throw new RefreshTokenNotFoundException();
        }

        $replacement = $this->issue($user, $refreshToken->getFamilyId(), $refreshToken->getFamilyExpiresAt());

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
     * Revokes every session of a user, on every device — e.g. after a password change.
     */
    public function revokeAllSessions(User $user): void
    {
        $this->repository->revokeAllForUser($user->getUserIdentifier());
    }

    /**
     * @return IssuedRefreshToken|null null if the family has already reached its absolute expiry
     */
    private function issue(User $user, Uuid $familyId, \DateTimeImmutable $familyExpiresAt): ?IssuedRefreshToken
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
