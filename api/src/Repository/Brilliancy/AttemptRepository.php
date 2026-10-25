<?php

declare(strict_types=1);

namespace App\Repository\Brilliancy;

use App\Entity\Brilliancy\Attempt;
use App\Entity\Training\Run;
use App\Entity\User;
use App\Enum\Brilliancy\AttemptStatus;
use App\Enum\Brilliancy\Kind;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * « Brillant ou Blunder » attempts are only changed under their run's lock (the run is locked first).
 *
 * @phpstan-type Counts array{played: int, right: int, wrong: int, brilliant: array{played: int, right: int}, blunder: array{played: int, right: int}}
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
            ->andWhere('a.run = :run')
            ->andWhere('a.status <> :pending')
            ->setParameter('run', $run->getId(), 'uuid')
            ->setParameter('pending', AttemptStatus::Pending->value)
            ->orderBy('a.servedAt', 'ASC')
            ->addOrderBy('a.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * The run's answers so far.
     *
     * @return Counts
     */
    public function countsOfRun(Run $run): array
    {
        return $this->counts('run_id', $run->getId()->toBinary());
    }

    /**
     * The user's answers, every run.
     *
     * @return Counts
     */
    public function countsOf(User $user): array
    {
        return $this->counts('user_id', $user->getId()->toBinary());
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
                'SELECT puzzle_id FROM brilliancy_attempt WHERE run_id = ?',
                [$run->getId()->toBinary()],
                [ParameterType::BINARY],
            ),
        );
    }

    /**
     * Among $puzzleIds, those the user was already shown (one indexed probe).
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
                'SELECT DISTINCT puzzle_id FROM brilliancy_attempt WHERE user_id = ? AND puzzle_id IN (?)',
                [$user->getId()->toBinary(), $puzzleIds],
                [ParameterType::BINARY, ArrayParameterType::INTEGER],
            ),
        );
    }

    /**
     * @return Counts
     */
    private function counts(string $column, string $id): array
    {
        $counts = ['played' => 0, 'right' => 0, 'wrong' => 0, Kind::Brilliant->value => ['played' => 0, 'right' => 0], Kind::Blunder->value => ['played' => 0, 'right' => 0]];
        foreach ($this->getEntityManager()->getConnection()->fetchAllAssociative(
            "SELECT kind, status, COUNT(*) AS n FROM brilliancy_attempt WHERE $column = ? AND status <> ? GROUP BY kind, status",
            [$id, AttemptStatus::Pending->value],
            [ParameterType::BINARY, ParameterType::STRING],
        ) as $row) {
            $kind = \is_string($row['kind']) ? Kind::tryFrom($row['kind']) : null;
            $status = \is_string($row['status']) ? AttemptStatus::tryFrom($row['status']) : null;
            $n = is_numeric($row['n']) ? (int) $row['n'] : 0;
            if (null === $kind || null === $status) {
                continue;
            }
            $right = AttemptStatus::Right === $status;
            $counts['played'] += $n;
            $counts[$right ? 'right' : 'wrong'] += $n;
            $counts[$kind->value]['played'] += $n;
            $counts[$kind->value]['right'] += $right ? $n : 0;
        }

        return $counts;
    }
}
