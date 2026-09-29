<?php

declare(strict_types=1);

namespace App\Repository\Activity;

use App\Entity\Activity\LogEntry;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<LogEntry>
 */
class LogEntryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LogEntry::class);
    }

    public function findOneBySource(string $sourceType, string $sourceId): ?LogEntry
    {
        return $this->findOneBy(['sourceType' => $sourceType, 'sourceId' => $sourceId]);
    }

    /**
     * @return list<LogEntry>
     */
    public function findByUser(User $user): array
    {
        /** @var list<LogEntry> */
        return $this->createQueryBuilder('e')
            ->where('e.user = :user')
            ->setParameter('user', $user->getId(), 'uuid')
            ->orderBy('e.occurredAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
