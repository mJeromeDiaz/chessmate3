<?php

declare(strict_types=1);

namespace App\Repository\Training;

use App\Entity\Training\Plan;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Plan>
 */
class PlanRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Plan::class);
    }

    public function findOwned(Uuid $id, User $user): ?Plan
    {
        return $this->findOneBy(['id' => $id, 'user' => $user]);
    }

    /**
     * @return list<Plan> most recently changed first
     */
    public function findByUser(User $user): array
    {
        return $this->findBy(['user' => $user], ['updatedAt' => 'DESC'], Plan::MAX_PER_USER);
    }

    public function countByUser(User $user): int
    {
        return $this->count(['user' => $user]);
    }

    /**
     * Saved sessions with a reminder (on demand ones never have one).
     *
     * @return list<Plan>
     */
    public function findWithReminder(): array
    {
        return $this->createQueryBuilder('p')
            ->addSelect('u')
            ->join('p.user', 'u')
            ->where('p.reminderEnabled = true')
            ->getQuery()
            ->getResult();
    }
}
