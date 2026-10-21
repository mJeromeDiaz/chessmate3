<?php

declare(strict_types=1);

namespace App\Repository\Calculation;

use App\Calculation\CalculationRules;
use App\Entity\Calculation\Attempt;
use App\Entity\Training\Run;
use App\Entity\User;
use App\Enum\Calculation\AttemptStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * The lines of the calculation module, only changed under their run's lock (the run is locked
 * first).
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
     * The run's lines by verdict, its streak (the lines right since the last failure, the longest)
     * and their time (each one capped).
     *
     * @return array{right: int, wrong: int, late: int, streak: int, bestStreak: int, activeMs: int}
     */
    public function statsOfRun(Run $run): array
    {
        $stats = ['right' => 0, 'wrong' => 0, 'late' => 0, 'streak' => 0, 'bestStreak' => 0, 'activeMs' => 0];
        foreach ($this->findResolvedOfRun($run) as $attempt) {
            ++$stats[match ($attempt->getStatus()) {
                AttemptStatus::Right => 'right',
                AttemptStatus::Late => 'late',
                default => 'wrong',
            }];
            $stats['streak'] = AttemptStatus::Right === $attempt->getStatus() ? $stats['streak'] + 1 : 0;
            $stats['bestStreak'] = max($stats['bestStreak'], $stats['streak']);
            $stats['activeMs'] += min(CalculationRules::ACTIVE_TIME_CAP_MS, $attempt->getDurationMs() ?? 0);
        }

        return $stats;
    }

    /**
     * The puzzles of a run so far, pending one included.
     *
     * @return list<int>
     */
    public function puzzleIdsOfRun(Run $run): array
    {
        return array_map(
            static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0,
            $this->getEntityManager()->getConnection()->fetchFirstColumn(
                'SELECT puzzle_id FROM calculation_attempt WHERE run_id = ?',
                [$run->getId()->toBinary()],
                [ParameterType::BINARY],
            ),
        );
    }

    /**
     * Among $puzzleIds, those the user already played in this module.
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
                'SELECT DISTINCT puzzle_id FROM calculation_attempt WHERE user_id = ? AND puzzle_id IN (?)',
                [$user->getId()->toBinary(), $puzzleIds],
                [ParameterType::BINARY, ArrayParameterType::INTEGER],
            ),
        );
    }
}
