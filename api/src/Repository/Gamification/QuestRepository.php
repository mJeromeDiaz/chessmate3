<?php

declare(strict_types=1);

namespace App\Repository\Gamification;

use App\Entity\Gamification\Quest;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Quest>
 */
class QuestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Quest::class);
    }

    public function findOfWeek(User $user, \DateTimeImmutable $weekStart): ?Quest
    {
        return $this->findOneBy(['user' => $user, 'weekStart' => $weekStart]);
    }

    /**
     * Quests of past weeks not completed (still to settle, at most the last 4).
     *
     * @return list<Quest>
     */
    public function findOpenBefore(User $user, \DateTimeImmutable $weekStart): array
    {
        /** @var list<Quest> */
        return $this->createQueryBuilder('q')
            ->where('q.user = :user')
            ->andWhere('q.weekStart < :week')
            ->andWhere('q.completedAt IS NULL')
            ->setParameter('user', $user->getId(), 'uuid')
            ->setParameter('week', $weekStart, 'date_immutable')
            ->orderBy('q.weekStart', 'DESC')
            ->setMaxResults(4)
            ->getQuery()
            ->getResult();
    }

    public function findOfWeekBefore(User $user, \DateTimeImmutable $weekStart): ?Quest
    {
        return $this->findOneBy(['user' => $user, 'weekStart' => $weekStart->modify('-7 days')]);
    }

    public function save(Quest $quest): void
    {
        $this->getEntityManager()->persist($quest);
        $this->getEntityManager()->flush();
    }
}
