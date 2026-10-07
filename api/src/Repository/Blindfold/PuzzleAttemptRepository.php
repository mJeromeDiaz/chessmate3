<?php

declare(strict_types=1);

namespace App\Repository\Blindfold;

use App\Blindfold\Puzzle\PuzzleRules;
use App\Entity\Blindfold\PuzzleAttempt;
use App\Entity\Training\Run;
use App\Entity\User;
use App\Enum\Blindfold\AttemptStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * Blindfold puzzle attempts are only changed under their run's lock (the run is locked first).
 *
 * @extends ServiceEntityRepository<PuzzleAttempt>
 */
class PuzzleAttemptRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PuzzleAttempt::class);
    }

    public function findOwned(Uuid $id, User $user): ?PuzzleAttempt
    {
        return $this->findOneBy(['id' => $id, 'user' => $user]);
    }

    /**
     * The puzzle served in the run and not submitted yet, if any.
     */
    public function findPendingOfRun(Run $run): ?PuzzleAttempt
    {
        return $this->findOneBy(['run' => $run, 'status' => AttemptStatus::Pending]);
    }

    /**
     * @return list<PuzzleAttempt> in the order played
     */
    public function findResolvedOfRun(Run $run): array
    {
        /** @var list<PuzzleAttempt> */
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
     * The puzzles of a run so far, pending one included (none is served twice in a run).
     *
     * @return list<int>
     */
    public function puzzleIdsOfRun(Run $run): array
    {
        return array_map(
            static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0,
            $this->getEntityManager()->getConnection()->fetchFirstColumn(
                'SELECT puzzle_id FROM blindfold_puzzle_attempt WHERE run_id = ?',
                [$run->getId()->toBinary()],
                [ParameterType::BINARY],
            ),
        );
    }

    /**
     * Among $puzzleIds, those the user already played blindfold (one indexed probe).
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
                'SELECT DISTINCT puzzle_id FROM blindfold_puzzle_attempt WHERE user_id = ? AND puzzle_id IN (?)',
                [$user->getId()->toBinary(), $puzzleIds],
                [ParameterType::BINARY, ArrayParameterType::INTEGER],
            ),
        );
    }

    /**
     * The run's resolved puzzles by status, and their time (each capped).
     *
     * @return array{solved: int, helped: int, failed: int, activeMs: int}
     */
    public function statsOfRun(Run $run): array
    {
        $stats = ['solved' => 0, 'helped' => 0, 'failed' => 0, 'activeMs' => 0];
        foreach ($this->findResolvedOfRun($run) as $attempt) {
            $stats[$attempt->getStatus()->value] = ($stats[$attempt->getStatus()->value] ?? 0) + 1;
            $stats['activeMs'] += min(PuzzleRules::ACTIVE_TIME_CAP_MS, $attempt->getDurationMs() ?? 0);
        }

        /** @var array{solved: int, helped: int, failed: int, activeMs: int} */
        return $stats;
    }

    /**
     * The user's resolved puzzles, by level and length, by status.
     *
     * @return list<array{level: string, length: int, status: string, count: int}>
     */
    public function countsOf(User $user): array
    {
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT level, length, status, COUNT(*) AS n FROM blindfold_puzzle_attempt
              WHERE user_id = ? AND status <> ? GROUP BY level, length, status',
            [$user->getId()->toBinary(), AttemptStatus::Pending->value],
            [ParameterType::BINARY],
        );

        return array_map(static fn (array $row): array => [
            'level' => \is_string($row['level']) ? $row['level'] : '',
            'length' => is_numeric($row['length']) ? (int) $row['length'] : 0,
            'status' => \is_string($row['status']) ? $row['status'] : '',
            'count' => is_numeric($row['n']) ? (int) $row['n'] : 0,
        ], $rows);
    }
}
