<?php

declare(strict_types=1);

namespace App\Repository\Woodpecker;

use App\Entity\Woodpecker\Cycle;
use App\Entity\Woodpecker\Set;
use App\Enum\Woodpecker\CycleStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Cycle>
 */
class CycleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Cycle::class);
    }

    /**
     * The run in progress (resting or active): at most one per set.
     */
    public function findOpen(Set $set): ?Cycle
    {
        /** @var Cycle|null */
        return $this->createQueryBuilder('c')
            ->where('c.set = :set AND c.status IN (:open)')
            ->setParameter('set', $set->getId(), 'uuid')
            ->setParameter('open', [CycleStatus::Resting->value, CycleStatus::Active->value])
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function maxNumber(Set $set): int
    {
        $max = $this->createQueryBuilder('c')
            ->select('MAX(c.number)')
            ->where('c.set = :set')
            ->setParameter('set', $set->getId(), 'uuid')
            ->getQuery()
            ->getSingleScalarResult();

        return is_numeric($max) ? (int) $max : 0;
    }

    /**
     * @return list<Cycle> by cycle number then run
     */
    public function findBySet(Set $set): array
    {
        return $this->findBy(['set' => $set], ['number' => 'ASC', 'run' => 'ASC']);
    }
}
