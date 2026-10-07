<?php

declare(strict_types=1);

namespace App\Repository\Coordinates;

use App\Entity\Coordinates\Series;
use App\Entity\Training\Run;
use App\Entity\User;
use App\Enum\Coordinates\Orientation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Series>
 */
class SeriesRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Series::class);
    }

    /**
     * The series of a run (locked with it: the run is always locked first).
     */
    public function findOfRun(Run $run): ?Series
    {
        return $this->findOneBy(['run' => $run]);
    }

    /**
     * The user's closed series, the latest first.
     *
     * @return list<Series>
     */
    public function closedOf(User $user, int $limit): array
    {
        /** @var list<Series> */
        return $this->createQueryBuilder('s')
            ->andWhere('s.user = :user')
            ->andWhere('s.closedAt IS NOT NULL')
            ->setParameter('user', $user->getId(), 'uuid')
            ->orderBy('s.startedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * The first series that validated an orientation, if any.
     */
    public function firstValidated(User $user, Orientation $orientation): ?Series
    {
        /** @var Series|null */
        return $this->createQueryBuilder('s')
            ->andWhere('s.user = :user')
            ->andWhere('s.orientation = :orientation')
            ->andWhere('s.validated = true')
            ->setParameter('user', $user->getId(), 'uuid')
            ->setParameter('orientation', $orientation->value)
            ->orderBy('s.closedAt', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * The closed series of an orientation with the most right answers (the earliest on a tie).
     */
    public function best(User $user, Orientation $orientation): ?Series
    {
        /** @var Series|null */
        return $this->createQueryBuilder('s')
            ->andWhere('s.user = :user')
            ->andWhere('s.orientation = :orientation')
            ->andWhere('s.closedAt IS NOT NULL')
            ->setParameter('user', $user->getId(), 'uuid')
            ->setParameter('orientation', $orientation->value)
            ->orderBy('s.successCount', 'DESC')
            ->addOrderBy('s.startedAt', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Closed series of an orientation.
     */
    public function countClosed(User $user, Orientation $orientation): int
    {
        $count = $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->andWhere('s.user = :user')
            ->andWhere('s.orientation = :orientation')
            ->andWhere('s.closedAt IS NOT NULL')
            ->setParameter('user', $user->getId(), 'uuid')
            ->setParameter('orientation', $orientation->value)
            ->getQuery()
            ->getSingleScalarResult();

        return is_numeric($count) ? (int) $count : 0;
    }
}
