<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\RefreshToken;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Gesdinet\JWTRefreshTokenBundle\Doctrine\DeleteRefreshTokenRepositoryInterface;
use Gesdinet\JWTRefreshTokenBundle\Doctrine\RefreshTokenRepositoryInterface;
use Gesdinet\JWTRefreshTokenBundle\Model\RefreshTokenInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<RefreshToken>
 * @implements RefreshTokenRepositoryInterface<RefreshToken>
 */
class RefreshTokenRepository extends ServiceEntityRepository implements RefreshTokenRepositoryInterface, DeleteRefreshTokenRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RefreshToken::class);
    }

    /**
     * Marks the token revoked, but only if it was still active — race-safe the same way the
     * bundle's own {@see \Gesdinet\JWTRefreshTokenBundle\Doctrine\RefreshTokenManager::delete()}
     * is: a conditional bulk UPDATE, so two concurrent requests presenting the same single-use
     * token can never both believe they were the one that consumed it.
     *
     * @return int 1 if this call is the one that revoked it, 0 if it was already revoked
     */
    public function revokeIfActive(RefreshToken $refreshToken): int
    {
        /** @var int $affected */
        $affected = $this->createQueryBuilder('rt')
            ->update()
            ->set('rt.revokedAt', ':now')
            ->where('rt.id = :id')
            ->andWhere('rt.revokedAt IS NULL')
            ->setParameter('now', new \DateTimeImmutable())
            ->setParameter('id', $refreshToken->getId())
            ->getQuery()
            ->execute();

        // A bulk UPDATE bypasses the UnitOfWork, so any already-loaded instance of this row (this
        // one included) is left with a stale, still-null revokedAt in memory. Without this, a
        // second lookup within the same process (identity map still warm — e.g. the next request
        // in a functional test, or a long-lived worker) would see the token as active again.
        // Doctrine ORM 3 dropped the per-entity-class form, so this clears everything: callers must
        // re-fetch (not reuse) any entity they still need afterwards.
        $this->getEntityManager()->clear();

        return $affected;
    }

    /**
     * Revokes every active token in the family — the response to a detected reuse.
     */
    public function revokeFamily(Uuid $familyId): int
    {
        /** @var int $affected */
        $affected = $this->createQueryBuilder('rt')
            ->update()
            ->set('rt.revokedAt', ':now')
            ->where('rt.familyId = :familyId')
            ->andWhere('rt.revokedAt IS NULL')
            ->setParameter('now', new \DateTimeImmutable())
            ->setParameter('familyId', $familyId, 'uuid')
            ->getQuery()
            ->execute();

        // See the comment in revokeIfActive(): without this, an already-loaded sibling token from
        // the same family (e.g. the one just issued to replace the one being reused) would keep
        // reporting itself as active for the rest of the process.
        $this->getEntityManager()->clear();

        return $affected;
    }

    /**
     * Revokes every active token of every family of a user — all their sessions, on every device.
     * Clears the entity manager for the same reason as {@see self::revokeIfActive()}.
     */
    public function revokeAllForUser(string $userIdentifier): int
    {
        /** @var int $affected */
        $affected = $this->createQueryBuilder('rt')
            ->update()
            ->set('rt.revokedAt', ':now')
            ->where('rt.username = :identifier')
            ->andWhere('rt.revokedAt IS NULL')
            ->setParameter('now', new \DateTimeImmutable())
            ->setParameter('identifier', $userIdentifier)
            ->getQuery()
            ->execute();

        $this->getEntityManager()->clear();

        return $affected;
    }

    /**
     * @return iterable<RefreshToken>
     */
    #[\Override]
    public function findInvalid(?\DateTimeInterface $datetime = null): iterable
    {
        /** @var list<RefreshToken> $tokens */
        $tokens = $this->createQueryBuilder('rt')
            ->where('rt.valid < :datetime')
            ->setParameter('datetime', $datetime ?? new \DateTime())
            ->getQuery()
            ->getResult();

        return $tokens;
    }

    /**
     * @return iterable<RefreshToken>
     */
    #[\Override]
    public function findInvalidBatch(?\DateTimeInterface $datetime = null, ?int $batchSize = null, int $offset = 0): iterable
    {
        /** @var list<RefreshToken> $tokens */
        $tokens = $this->createQueryBuilder('rt')
            ->where('rt.valid < :datetime')
            ->setParameter('datetime', $datetime ?? new \DateTime())
            ->setFirstResult($offset)
            ->setMaxResults($batchSize)
            ->getQuery()
            ->getResult();

        return $tokens;
    }

    #[\Override]
    public function deleteByUser(UserInterface $user): int
    {
        /** @var int $deleted */
        $deleted = $this->createQueryBuilder('rt')
            ->delete()
            ->where('rt.username = :identifier')
            ->setParameter('identifier', $user->getUserIdentifier())
            ->getQuery()
            ->execute();

        return $deleted;
    }

    #[\Override]
    public function deleteAllButNewestForUser(UserInterface $user, int $keep): int
    {
        /** @var list<RefreshToken> $stale */
        $stale = $this->createQueryBuilder('rt')
            ->where('rt.username = :identifier')
            ->setParameter('identifier', $user->getUserIdentifier())
            ->orderBy('rt.valid', 'DESC')
            ->setFirstResult($keep)
            ->getQuery()
            ->getResult();

        if ([] === $stale) {
            return 0;
        }

        /** @var int $deleted */
        $deleted = $this->createQueryBuilder('rt')
            ->delete()
            ->where('rt.id IN (:ids)')
            ->setParameter('ids', array_map(static fn (RefreshToken $token): int|string|null => $token->getId(), $stale))
            ->getQuery()
            ->execute();

        return $deleted;
    }

    #[\Override]
    public function deleteToken(RefreshTokenInterface $refreshToken): int
    {
        /** @var int $deleted */
        $deleted = $this->createQueryBuilder('rt')
            ->delete()
            ->where('rt.id = :id')
            ->setParameter('id', $refreshToken->getId())
            ->getQuery()
            ->execute();

        return $deleted;
    }
}
