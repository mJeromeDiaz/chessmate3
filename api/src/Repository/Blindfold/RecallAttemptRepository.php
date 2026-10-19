<?php

declare(strict_types=1);

namespace App\Repository\Blindfold;

use App\Blindfold\Recall\RecallRules;
use App\Entity\Blindfold\RecallAttempt;
use App\Entity\Training\Run;
use App\Entity\User;
use App\Enum\Blindfold\RecallStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * Positions to rebuild are only changed under their run's lock (the run is locked first).
 *
 * @phpstan-type Counts array{played: int, exact: int, close: int, missed: int}
 *
 * @extends ServiceEntityRepository<RecallAttempt>
 */
class RecallAttemptRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RecallAttempt::class);
    }

    public function findOwned(Uuid $id, User $user): ?RecallAttempt
    {
        return $this->findOneBy(['id' => $id, 'user' => $user]);
    }

    /**
     * The position served in the run and not submitted yet, if any.
     */
    public function findPendingOfRun(Run $run): ?RecallAttempt
    {
        return $this->findOneBy(['run' => $run, 'status' => RecallStatus::Pending]);
    }

    /**
     * @return list<RecallAttempt> in the order played
     */
    public function findResolvedOfRun(Run $run): array
    {
        /** @var list<RecallAttempt> */
        return $this->createQueryBuilder('a')
            ->andWhere('a.run = :run')
            ->andWhere('a.status <> :pending')
            ->setParameter('run', $run->getId(), 'uuid')
            ->setParameter('pending', RecallStatus::Pending->value)
            ->orderBy('a.startedAt', 'ASC')
            ->addOrderBy('a.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * The run's rebuilds by verdict, their accuracy summed (for the mean), their time (each one
     * capped), and the level opened during the run if any.
     *
     * @return array{exact: int, close: int, missed: int, accuracySum: float, activeMs: int, unlockedLevel: int|null}
     */
    public function statsOfRun(Run $run): array
    {
        $stats = ['exact' => 0, 'close' => 0, 'missed' => 0, 'accuracySum' => 0.0, 'activeMs' => 0, 'unlockedLevel' => null];
        foreach ($this->findResolvedOfRun($run) as $attempt) {
            $verdict = match ($attempt->getStatus()) {
                RecallStatus::Exact => 'exact',
                RecallStatus::Close => 'close',
                default => 'missed',
            };
            ++$stats[$verdict];
            $stats['accuracySum'] += $attempt->getAccuracy();
            $stats['activeMs'] += min(RecallRules::ACTIVE_TIME_CAP_MS, $attempt->getDurationMs() ?? 0);
            if ($attempt->isUnlocked()) {
                $stats['unlockedLevel'] = max($stats['unlockedLevel'] ?? 0, $attempt->getLevel() + 1);
            }
        }

        /** @var array{exact: int, close: int, missed: int, accuracySum: float, activeMs: int, unlockedLevel: int|null} */
        return $stats;
    }

    /**
     * The user's rebuilds at each level played (levels never played are absent).
     *
     * @return array<int, Counts>
     */
    public function countsByLevel(User $user): array
    {
        $counts = [];
        foreach ($this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT level, status, COUNT(*) AS n FROM blindfold_recall_attempt WHERE user_id = ? AND status <> ? GROUP BY level, status',
            [$user->getId()->toBinary(), RecallStatus::Pending->value],
            [ParameterType::BINARY, ParameterType::STRING],
        ) as $row) {
            $level = is_numeric($row['level']) ? (int) $row['level'] : 0;
            $status = \is_string($row['status']) ? $row['status'] : '';
            $n = is_numeric($row['n']) ? (int) $row['n'] : 0;
            $counts[$level] ??= ['played' => 0, 'exact' => 0, 'close' => 0, 'missed' => 0];
            $counts[$level]['played'] += $n;
            match ($status) {
                RecallStatus::Exact->value => $counts[$level]['exact'] += $n,
                RecallStatus::Close->value => $counts[$level]['close'] += $n,
                default => $counts[$level]['missed'] += $n,
            };
        }

        return $counts;
    }

    /**
     * The user's exact rebuilds at a level.
     */
    public function countExact(User $user, int $level): int
    {
        $count = $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM blindfold_recall_attempt WHERE user_id = ? AND level = ? AND status = ?',
            [$user->getId()->toBinary(), $level, RecallStatus::Exact->value],
            [ParameterType::BINARY, ParameterType::INTEGER, ParameterType::STRING],
        );

        return is_numeric($count) ? (int) $count : 0;
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
                'SELECT puzzle_id FROM blindfold_recall_attempt WHERE run_id = ? AND puzzle_id IS NOT NULL',
                [$run->getId()->toBinary()],
                [ParameterType::BINARY],
            ),
        );
    }

    /**
     * Among $puzzleIds, those the user already rebuilt.
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
                'SELECT DISTINCT puzzle_id FROM blindfold_recall_attempt WHERE user_id = ? AND puzzle_id IN (?)',
                [$user->getId()->toBinary(), $puzzleIds],
                [ParameterType::BINARY, ArrayParameterType::INTEGER],
            ),
        );
    }
}
