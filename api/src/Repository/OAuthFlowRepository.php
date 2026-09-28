<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\OAuthFlow;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<OAuthFlow>
 */
class OAuthFlowRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OAuthFlow::class);
    }

    public function findOneByBindingHash(string $bindingHash): ?OAuthFlow
    {
        return $this->findOneBy(['bindingHash' => $bindingHash]);
    }

    /**
     * Marks the flow consumed if it is still unused and unexpired — a conditional UPDATE, so of two
     * concurrent callbacks for the same flow only one can proceed.
     *
     * @return bool whether this call consumed it
     */
    public function consumeIfActive(OAuthFlow $flow): bool
    {
        $now = new \DateTimeImmutable();

        /** @var int $affected */
        $affected = $this->createQueryBuilder('f')
            ->update()
            ->set('f.consumedAt', ':now')
            ->where('f.id = :id')
            ->andWhere('f.consumedAt IS NULL')
            ->andWhere('f.expiresAt > :now')
            ->setParameter('id', $flow->getId(), 'uuid')
            ->setParameter('now', $now)
            ->getQuery()
            ->execute();

        return 1 === $affected;
    }

    /**
     * Housekeeping, called whenever a flow starts: abandoned flows are useless once expired.
     */
    public function deleteExpired(): int
    {
        /** @var int $deleted */
        $deleted = $this->createQueryBuilder('f')
            ->delete()
            ->where('f.expiresAt < :cutoff')
            ->setParameter('cutoff', new \DateTimeImmutable('-1 hour'))
            ->getQuery()
            ->execute();

        return $deleted;
    }

    public function save(OAuthFlow $flow): void
    {
        $this->getEntityManager()->persist($flow);
        $this->getEntityManager()->flush();
    }
}
