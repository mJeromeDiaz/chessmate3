<?php

declare(strict_types=1);

namespace App\Repository\PuzzleStreak;

use App\Entity\PuzzleStreak\Attempt;
use App\Entity\PuzzleStreak\Streak;
use App\Entity\Training\Run;
use App\Entity\User;
use App\Enum\PuzzleStreak\AttemptStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * Puzzles of the streaks are only changed under their run's lock (the run is locked first).
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
     * When the streak's last puzzle was submitted, null before its first.
     */
    public function lastSubmittedAt(Streak $streak): ?\DateTimeImmutable
    {
        $at = $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT MAX(submitted_at) FROM puzzle_streak_attempt WHERE streak_id = ?',
            [$streak->getId()->toBinary()],
            [ParameterType::BINARY],
        );

        return \is_string($at) ? new \DateTimeImmutable($at, new \DateTimeZone('UTC')) : null;
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
                'SELECT puzzle_id FROM puzzle_streak_attempt WHERE run_id = ?',
                [$run->getId()->toBinary()],
                [ParameterType::BINARY],
            ),
        );
    }

    /**
     * Among $puzzleIds, those the user already played in a streak (one indexed probe).
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
                'SELECT DISTINCT puzzle_id FROM puzzle_streak_attempt WHERE user_id = ? AND puzzle_id IN (?)',
                [$user->getId()->toBinary(), $puzzleIds],
                [ParameterType::BINARY, ArrayParameterType::INTEGER],
            ),
        );
    }

    /**
     * The user's puzzles solved and missed in streaks.
     *
     * @return array{solved: int, failed: int}
     */
    public function totalsOf(User $user): array
    {
        $totals = ['solved' => 0, 'failed' => 0];
        foreach ($this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT status, COUNT(*) AS n FROM puzzle_streak_attempt WHERE user_id = ? AND status <> ? GROUP BY status',
            [$user->getId()->toBinary(), AttemptStatus::Pending->value],
            [ParameterType::BINARY],
        ) as $row) {
            if (\is_string($row['status']) && isset($totals[$row['status']])) {
                $totals[$row['status']] = is_numeric($row['n']) ? (int) $row['n'] : 0;
            }
        }

        return $totals;
    }
}
