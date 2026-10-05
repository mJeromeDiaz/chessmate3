<?php

declare(strict_types=1);

namespace App\Repository\EarlyAccess;

use App\Entity\EarlyAccess\InvitationKey;
use App\Enum\EarlyAccess\InvitationStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<InvitationKey>
 */
class InvitationKeyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InvitationKey::class);
    }

    public function findByKeyHash(string $keyHash): ?InvitationKey
    {
        return $this->findOneBy(['keyHash' => $keyHash]);
    }

    /**
     * The admin list, newest first. The status is derived from the dates ({@see InvitationKey::getStatus()}).
     *
     * @param string|null $email part of the address, case-insensitive
     */
    public function createListQueryBuilder(
        \DateTimeImmutable $now,
        ?InvitationStatus $status = null,
        ?string $email = null,
        ?\DateTimeImmutable $createdAfter = null,
        ?\DateTimeImmutable $createdBefore = null,
    ): QueryBuilder {
        $qb = $this->createQueryBuilder('k')
            ->leftJoin('k.createdBy', 'c')->addSelect('c')
            ->leftJoin('k.usedBy', 'u')->addSelect('u')
            ->orderBy('k.createdAt', 'DESC')
            ->addOrderBy('k.id', 'DESC');

        if (InvitationStatus::Used === $status) {
            $qb->andWhere('k.usedAt IS NOT NULL');
        } elseif (InvitationStatus::Revoked === $status) {
            $qb->andWhere('k.usedAt IS NULL AND k.revokedAt IS NOT NULL');
        } elseif (InvitationStatus::Expired === $status) {
            $qb->andWhere('k.usedAt IS NULL AND k.revokedAt IS NULL AND k.expiresAt <= :now')->setParameter('now', $now);
        } elseif (InvitationStatus::Pending === $status) {
            $qb->andWhere('k.usedAt IS NULL AND k.revokedAt IS NULL AND (k.expiresAt IS NULL OR k.expiresAt > :now)')->setParameter('now', $now);
        }
        if (null !== $email && '' !== $email) {
            $qb->andWhere('k.email LIKE :email')->setParameter('email', '%'.addcslashes($email, '%_\\').'%');
        }
        if (null !== $createdAfter) {
            $qb->andWhere('k.createdAt >= :after')->setParameter('after', $createdAfter);
        }
        if (null !== $createdBefore) {
            $qb->andWhere('k.createdAt < :before')->setParameter('before', $createdBefore);
        }

        return $qb;
    }
}
