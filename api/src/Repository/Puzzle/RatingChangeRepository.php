<?php

namespace App\Repository\Puzzle;

use App\Entity\Puzzle\RatingChange;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RatingChange>
 */
class RatingChangeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RatingChange::class);
    }

    /**
     * The user's latest rating change (UUID v7 ids: their order is their order of creation).
     */
    public function findLatestOf(User $user): ?RatingChange
    {
        /** @var RatingChange|null */
        return $this->createQueryBuilder('r')
            ->where('r.user = :user')
            ->setParameter('user', $user->getId(), 'uuid')
            ->orderBy('r.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
