<?php

declare(strict_types=1);

namespace App\Repository\Woodpecker;

use App\Entity\Woodpecker\Growth;
use App\Entity\Woodpecker\Set;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Growth>
 */
class GrowthRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Growth::class);
    }

    /**
     * Puzzles added to the set from $since on (e.g. during a run).
     */
    public function sumAddedSince(Set $set, \DateTimeImmutable $since): int
    {
        $sum = $this->createQueryBuilder('g')
            ->select('SUM(g.added)')
            ->where('g.set = :set AND g.occurredAt >= :since')
            ->setParameter('set', $set->getId(), 'uuid')
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();

        return is_numeric($sum) ? (int) $sum : 0;
    }

    /**
     * @return list<Growth> oldest first
     */
    public function findBySet(Set $set): array
    {
        return $this->findBy(['set' => $set], ['occurredAt' => 'ASC', 'id' => 'ASC']);
    }
}
