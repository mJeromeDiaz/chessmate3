<?php

declare(strict_types=1);

namespace App\Repository\Notation;

use App\Entity\Notation\Attempt;
use App\Entity\Training\Run;
use App\Entity\User;
use App\Enum\Notation\AttemptStatus;
use App\Notation\NotationRules;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * The items of the notation module, only changed under their run's lock (the run is locked first).
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
     * The run's items by verdict and their time (each one capped).
     *
     * @return array{right: int, wrong: int, activeMs: int}
     */
    public function statsOfRun(Run $run): array
    {
        $stats = ['right' => 0, 'wrong' => 0, 'activeMs' => 0];
        foreach ($this->findResolvedOfRun($run) as $attempt) {
            ++$stats[AttemptStatus::Right === $attempt->getStatus() ? 'right' : 'wrong'];
            $stats['activeMs'] += min(NotationRules::ACTIVE_TIME_CAP_MS, $attempt->getDurationMs() ?? 0);
        }

        return $stats;
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
                'SELECT puzzle_id FROM notation_attempt WHERE run_id = ? AND puzzle_id IS NOT NULL',
                [$run->getId()->toBinary()],
                [ParameterType::BINARY],
            ),
        );
    }

    /**
     * The latest item of a run drawn from a legendary game (its line key `game:<id>`), if any.
     */
    public function lastGameOfRun(Run $run): ?Attempt
    {
        /** @var Attempt|null */
        return $this->createQueryBuilder('a')
            ->andWhere('a.run = :run')
            ->andWhere('a.lineKey LIKE :game')
            ->setParameter('run', $run->getId(), 'uuid')
            ->setParameter('game', 'game:%')
            ->orderBy('a.startedAt', 'DESC')
            ->addOrderBy('a.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * The repertoire lines of a run so far, pending one included.
     *
     * @return list<string>
     */
    public function lineKeysOfRun(Run $run): array
    {
        return array_values(array_filter(
            array_map(
                static fn (mixed $key): string => \is_string($key) ? $key : '',
                $this->getEntityManager()->getConnection()->fetchFirstColumn(
                    'SELECT line_key FROM notation_attempt WHERE run_id = ? AND line_key IS NOT NULL',
                    [$run->getId()->toBinary()],
                    [ParameterType::BINARY],
                ),
            ),
            static fn (string $key): bool => '' !== $key,
        ));
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
                'SELECT DISTINCT puzzle_id FROM notation_attempt WHERE user_id = ? AND puzzle_id IN (?)',
                [$user->getId()->toBinary(), $puzzleIds],
                [ParameterType::BINARY, ArrayParameterType::INTEGER],
            ),
        );
    }
}
