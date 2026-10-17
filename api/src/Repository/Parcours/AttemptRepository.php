<?php

declare(strict_types=1);

namespace App\Repository\Parcours;

use App\Entity\Parcours\Attempt;
use App\Entity\Training\Run;
use App\Entity\User;
use App\Enum\Parcours\AttemptStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * Puzzles of the parcours tiers, only changed under their run's lock (the run is locked first).
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

    public function findPendingOfRun(Run $run): ?Attempt
    {
        return $this->findOneBy(['run' => $run, 'status' => AttemptStatus::Pending]);
    }

    /**
     * @return list<Attempt> in the order they were served
     */
    public function findResolvedOfRun(Run $run): array
    {
        /** @var list<Attempt> */
        return $this->createQueryBuilder('a')
            ->andWhere('a.run = :run')
            ->andWhere('a.status <> :pending')
            ->setParameter('run', $run->getId(), 'uuid')
            ->setParameter('pending', AttemptStatus::Pending->value)
            ->orderBy('a.position', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** The tier's puzzles answered so far. */
    public function countResolvedOfRun(Run $run): int
    {
        $count = $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM parcours_attempt WHERE run_id = ? AND status <> ?',
            [$run->getId()->toBinary(), AttemptStatus::Pending->value],
            [ParameterType::BINARY, ParameterType::STRING],
        );

        return is_numeric($count) ? (int) $count : 0;
    }

    /**
     * The puzzles already served in the run's parcours (its session's runs; the run alone outside
     * a session): never twice in a parcours.
     *
     * @return list<int>
     */
    public function puzzleIdsOfParcours(Run $run): array
    {
        $parentId = $run->getParentId();

        return array_map(
            static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0,
            null === $parentId
                ? $this->getEntityManager()->getConnection()->fetchFirstColumn(
                    'SELECT puzzle_id FROM parcours_attempt WHERE run_id = ?',
                    [$run->getId()->toBinary()],
                    [ParameterType::BINARY],
                )
                : $this->getEntityManager()->getConnection()->fetchFirstColumn(
                    'SELECT a.puzzle_id FROM parcours_attempt a JOIN training_run r ON r.id = a.run_id WHERE r.parent_id = ?',
                    [$parentId->toBinary()],
                    [ParameterType::BINARY],
                ),
        );
    }

    /**
     * Among $puzzleIds, those the user already played in a parcours (one indexed probe).
     *
     * @param list<int> $puzzleIds
     *
     * @return list<int>
     */
    public function playedAmong(User $user, array $puzzleIds): array
    {
        if ([] === $puzzleIds) {
            return [];
        }

        return array_map(
            static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0,
            $this->getEntityManager()->getConnection()->fetchFirstColumn(
                'SELECT DISTINCT puzzle_id FROM parcours_attempt WHERE user_id = ? AND puzzle_id IN (?)',
                [$user->getId()->toBinary(), $puzzleIds],
                [ParameterType::BINARY, ArrayParameterType::INTEGER],
            ),
        );
    }
}
