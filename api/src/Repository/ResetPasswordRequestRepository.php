<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ResetPasswordRequest;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use SymfonyCasts\Bundle\ResetPassword\Model\ResetPasswordRequestInterface;
use SymfonyCasts\Bundle\ResetPassword\Persistence\Repository\ResetPasswordRequestRepositoryTrait;
use SymfonyCasts\Bundle\ResetPassword\Persistence\ResetPasswordRequestRepositoryInterface;

/**
 * @extends ServiceEntityRepository<ResetPasswordRequest>
 */
class ResetPasswordRequestRepository extends ServiceEntityRepository implements ResetPasswordRequestRepositoryInterface
{
    use ResetPasswordRequestRepositoryTrait;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ResetPasswordRequest::class);
    }

    #[\Override]
    public function createResetPasswordRequest(object $user, \DateTimeInterface $expiresAt, string $selector, string $hashedToken): ResetPasswordRequestInterface
    {
        if (!$user instanceof User) {
            throw new \InvalidArgumentException(sprintf('Expected an instance of "%s".', User::class));
        }

        return new ResetPasswordRequest($user, $expiresAt, $selector, $hashedToken);
    }

    /**
     * Stable across calls and part of the HMAC'd token data, so a token only verifies for the user
     * it was issued to.
     */
    #[\Override]
    public function getUserIdentifier(object $user): string
    {
        if (!$user instanceof User) {
            throw new \InvalidArgumentException(sprintf('Expected an instance of "%s".', User::class));
        }

        return $user->getId()->toRfc4122();
    }

    /**
     * Overrides the bundle trait's version, which binds the User entity itself as the query
     * parameter: with our BINARY(16) UUID ids that is sent untyped, as a string, and silently
     * matches nothing — which here would disable the per-user throttle.
     */
    #[\Override]
    public function getMostRecentNonExpiredRequestDate(object $user): ?\DateTimeInterface
    {
        if (!$user instanceof User) {
            return null;
        }

        /** @var ResetPasswordRequest|null $latest */
        $latest = $this->createQueryBuilder('r')
            ->where('r.user = :user')
            ->setParameter('user', $user->getId(), 'uuid')
            ->orderBy('r.requestedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return null !== $latest && !$latest->isExpired() ? $latest->getRequestedAt() : null;
    }

    /**
     * Deletes every reset request of the user (not only the one used), so a successful reset also
     * kills any other link still sitting in the mailbox. Same typed-parameter fix as above — the
     * trait's version would silently delete nothing, leaving the link reusable.
     */
    #[\Override]
    public function removeResetPasswordRequest(ResetPasswordRequestInterface $resetPasswordRequest): void
    {
        $user = $resetPasswordRequest->getUser();

        if ($user instanceof User) {
            $this->removeAllForUser($user);
        }
    }

    /**
     * @return int how many requests were deleted — 0 means another request already consumed them,
     *             which is how a reset link is made single-use even under concurrent submissions
     */
    public function removeAllForUser(User $user): int
    {
        /** @var int $deleted */
        $deleted = $this->createQueryBuilder('r')
            ->delete()
            ->where('r.user = :user')
            ->setParameter('user', $user->getId(), 'uuid')
            ->getQuery()
            ->execute();

        return $deleted;
    }
}
