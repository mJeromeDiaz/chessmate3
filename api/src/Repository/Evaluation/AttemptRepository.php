<?php

declare(strict_types=1);

namespace App\Repository\Evaluation;

use App\Entity\Evaluation\Attempt;
use App\Entity\Training\Run;
use App\Entity\User;
use App\Enum\Evaluation\AttemptStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * Evaluation attempts are only changed under their run's lock (the run is locked first).
 *
 * @extends ServiceEntityRepository<Attempt>
 */
class AttemptRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Attempt::class);
    }

    public function findOwned(Uuid $id, User $user): ?Attempt
    {
        return $this->findOneBy(['id' => $id, 'user' => $user]);
    }

    /**
     * The position served in the run and not answered yet, if any.
     */
    public function findPendingOfRun(Run $run): ?Attempt
    {
        return $this->findOneBy(['run' => $run, 'status' => AttemptStatus::Pending]);
    }

    /**
     * @return list<Attempt> in the order played
     */
    public function findResolvedOfRun(Run $run): array
    {
        /** @var list<Attempt> */
        return $this->createQueryBuilder('a')
            ->addSelect('p')
            ->join('a.position', 'p')
            ->andWhere('a.run = :run')
            ->andWhere('a.status <> :pending')
            ->setParameter('run', $run->getId(), 'uuid')
            ->setParameter('pending', AttemptStatus::Pending->value)
            ->orderBy('a.servedAt', 'ASC')
            ->addOrderBy('a.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function countResolvedOfRun(Run $run): int
    {
        $count = $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM evaluation_attempt WHERE run_id = ? AND status <> ?',
            [$run->getId()->toBinary(), AttemptStatus::Pending->value],
            [ParameterType::BINARY],
        );

        return is_numeric($count) ? (int) $count : 0;
    }

    /**
     * The user's answered positions by status, and how many had the right plan.
     *
     * @return array{exact: int, close: int, miss: int, timeout: int, planOk: int}
     */
    public function countsOf(User $user): array
    {
        $counts = ['exact' => 0, 'close' => 0, 'miss' => 0, 'timeout' => 0, 'planOk' => 0];
        foreach ($this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT status, COUNT(*) AS n, SUM(plan_ok = 1) AS plans FROM evaluation_attempt
              WHERE user_id = ? AND status <> ? GROUP BY status',
            [$user->getId()->toBinary(), AttemptStatus::Pending->value],
            [ParameterType::BINARY],
        ) as $row) {
            if (\is_string($row['status']) && isset($counts[$row['status']]) && is_numeric($row['n'])) {
                $counts[$row['status']] = (int) $row['n'];
                $counts['planOk'] += is_numeric($row['plans']) ? (int) $row['plans'] : 0;
            }
        }

        return $counts;
    }
}
