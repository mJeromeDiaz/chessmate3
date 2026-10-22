<?php

declare(strict_types=1);

namespace App\Repository\Endgame;

use App\Entity\Endgame\Attempt;
use App\Entity\Training\Run;
use App\Entity\User;
use App\Enum\Endgame\AttemptStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
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
     * The position served in the run and not played out yet, if any.
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
            'SELECT COUNT(*) FROM endgame_attempt WHERE run_id = ? AND status <> ?',
            [$run->getId()->toBinary(), AttemptStatus::Pending->value],
            [ParameterType::BINARY],
        );

        return is_numeric($count) ? (int) $count : 0;
    }

    /**
     * The pool positions already served in the run, not to serve them again.
     *
     * @return list<int>
     */
    public function servedPositionIds(Run $run): array
    {
        $ids = $this->getEntityManager()->getConnection()->fetchFirstColumn(
            'SELECT position_id FROM endgame_attempt WHERE run_id = ? AND position_id IS NOT NULL',
            [$run->getId()->toBinary()],
            [ParameterType::BINARY],
        );

        return array_map(static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0, $ids);
    }
}
