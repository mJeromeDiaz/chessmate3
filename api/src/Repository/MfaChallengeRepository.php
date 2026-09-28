<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\MfaChallenge;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MfaChallenge>
 */
class MfaChallengeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MfaChallenge::class);
    }

    public function findOneByPendingTokenHash(string $pendingTokenHash): ?MfaChallenge
    {
        return $this->findOneBy(['pendingTokenHash' => $pendingTokenHash]);
    }

    /**
     * Atomically spends one of the challenge's attempts, but only while it's still usable (not
     * consumed, not locked, not expired, attempts left). Reserving the attempt *before* comparing
     * the code — as a conditional bulk UPDATE rather than a read-modify-write on the entity — is
     * what keeps the 5-attempt cap real under concurrency: N parallel guesses against the same
     * pending token can never all read "attempts = 0" and each believe they're within budget.
     *
     * The passed instance is refreshed from the database afterwards (a bulk UPDATE bypasses the
     * UnitOfWork — see {@see RefreshTokenRepository::revokeIfActive()}).
     *
     * @return bool whether an attempt was reserved; false means the challenge is no longer usable
     */
    public function reserveAttempt(MfaChallenge $challenge): bool
    {
        $now = new \DateTimeImmutable();

        /** @var int $affected */
        $affected = $this->createQueryBuilder('c')
            ->update()
            ->set('c.attempts', 'c.attempts + 1')
            ->where('c.id = :id')
            ->andWhere('c.consumedAt IS NULL')
            ->andWhere('c.lockedAt IS NULL')
            ->andWhere('c.expiresAt >= :now')
            ->andWhere('c.attempts < :max')
            ->setParameter('id', $challenge->getId(), 'uuid')
            ->setParameter('now', $now)
            ->setParameter('max', MfaChallenge::MAX_ATTEMPTS)
            ->getQuery()
            ->execute();

        $this->getEntityManager()->refresh($challenge);

        return 1 === $affected;
    }

    /**
     * Marks the challenge consumed, but only if nothing else consumed or locked it first — so the
     * same code submitted twice concurrently can only ever open one session.
     *
     * @return bool whether this call is the one that consumed it
     */
    public function consumeIfActive(MfaChallenge $challenge): bool
    {
        /** @var int $affected */
        $affected = $this->createQueryBuilder('c')
            ->update()
            ->set('c.consumedAt', ':now')
            ->where('c.id = :id')
            ->andWhere('c.consumedAt IS NULL')
            ->andWhere('c.lockedAt IS NULL')
            ->setParameter('id', $challenge->getId(), 'uuid')
            ->setParameter('now', new \DateTimeImmutable())
            ->getQuery()
            ->execute();

        $this->getEntityManager()->refresh($challenge);

        return 1 === $affected;
    }

    /**
     * Locks the challenge once its attempt budget is spent. Idempotent and race-safe: whichever
     * failed attempt gets here first sets it, the others match zero rows.
     *
     * @return bool whether this call is the one that locked it
     */
    public function lockIfExhausted(MfaChallenge $challenge): bool
    {
        /** @var int $affected */
        $affected = $this->createQueryBuilder('c')
            ->update()
            ->set('c.lockedAt', ':now')
            ->where('c.id = :id')
            ->andWhere('c.lockedAt IS NULL')
            ->andWhere('c.attempts >= :max')
            ->setParameter('id', $challenge->getId(), 'uuid')
            ->setParameter('now', new \DateTimeImmutable())
            ->setParameter('max', MfaChallenge::MAX_ATTEMPTS)
            ->getQuery()
            ->execute();

        $this->getEntityManager()->refresh($challenge);

        return 1 === $affected;
    }

    /**
     * Invalidates every still-usable challenge of a user — called when a new login attempt starts,
     * so only the code from the most recent email works (and an attacker who holds the password
     * can't farm several challenges in parallel to multiply their 5-guess budget).
     */
    public function invalidateActiveForUser(User $user): int
    {
        /** @var int $affected */
        $affected = $this->createQueryBuilder('c')
            ->update()
            ->set('c.lockedAt', ':now')
            ->where('c.user = :user')
            ->andWhere('c.consumedAt IS NULL')
            ->andWhere('c.lockedAt IS NULL')
            ->setParameter('user', $user->getId(), 'uuid')
            ->setParameter('now', new \DateTimeImmutable())
            ->getQuery()
            ->execute();

        return $affected;
    }

    public function save(MfaChallenge $challenge, bool $flush = true): void
    {
        $this->getEntityManager()->persist($challenge);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
