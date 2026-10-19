<?php

declare(strict_types=1);

namespace App\Repository\PuzzleRush;

use App\Entity\PuzzleRush\Attempt;
use App\Entity\Training\Run;
use App\Entity\User;
use App\Enum\PuzzleRush\AttemptStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * Puzzles of the rushes are only changed under their run's lock (the run is locked first).
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
     * The puzzle served in the run and not submitted yet, if any.
     */
    public function findPendingOfRun(Run $run): ?Attempt
    {
        return $this->findOneBy(['run' => $run, 'status' => AttemptStatus::Pending]);
    }

    /**
     * The run's first puzzle: its target is where the ramp started.
     */
    public function findFirstOfRun(Run $run): ?Attempt
    {
        return $this->findOneBy(['run' => $run], ['startedAt' => 'ASC', 'id' => 'ASC']);
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
            ->orderBy('a.startedAt', 'ASC')
            ->addOrderBy('a.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * The run's puzzles solved and failed so far.
     *
     * @return array{solved: int, failed: int}
     */
    public function countsOfRun(Run $run): array
    {
        $counts = ['solved' => 0, 'failed' => 0];
        foreach ($this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT status, COUNT(*) AS n FROM puzzle_rush_attempt WHERE run_id = ? AND status <> ? GROUP BY status',
            [$run->getId()->toBinary(), AttemptStatus::Pending->value],
            [ParameterType::BINARY, ParameterType::STRING],
        ) as $row) {
            if (\is_string($row['status']) && isset($counts[$row['status']])) {
                $counts[$row['status']] = is_numeric($row['n']) ? (int) $row['n'] : 0;
            }
        }

        return $counts;
    }

    /**
     * The puzzles of a run so far, pending one included (none is served twice in a run).
     *
     * @return list<int>
     */
    public function puzzleIdsOfRun(Run $run): array
    {
        return array_map(
            static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0,
            $this->getEntityManager()->getConnection()->fetchFirstColumn(
                'SELECT puzzle_id FROM puzzle_rush_attempt WHERE run_id = ?',
                [$run->getId()->toBinary()],
                [ParameterType::BINARY],
            ),
        );
    }

    /**
     * Among $puzzleIds, those the user already played in a rush (one indexed probe).
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
                'SELECT DISTINCT puzzle_id FROM puzzle_rush_attempt WHERE user_id = ? AND puzzle_id IN (?)',
                [$user->getId()->toBinary(), $puzzleIds],
                [ParameterType::BINARY, ArrayParameterType::INTEGER],
            ),
        );
    }
}
