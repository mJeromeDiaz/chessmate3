<?php

declare(strict_types=1);

namespace App\Repository\Woodpecker;

use App\Entity\Training\Run;
use App\Entity\User;
use App\Entity\Woodpecker\Attempt;
use App\Entity\Woodpecker\Cycle;
use App\Entity\Woodpecker\Set;
use App\Enum\Puzzle\AttemptStatus;
use App\Woodpecker\Stats\CycleStats;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\LockMode;
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

    /**
     * The attempt if it belongs to one of the user's sets, or null (never "forbidden").
     */
    public function findOwned(Uuid $id, User $user): ?Attempt
    {
        /** @var Attempt|null */
        return $this->createQueryBuilder('a')
            ->join('a.cycle', 'c')
            ->join('c.set', 's')
            ->where('a.id = :id AND s.user = :user')
            ->setParameter('id', $id, 'uuid')
            ->setParameter('user', $user->getId(), 'uuid')
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function lock(Attempt $attempt): void
    {
        $this->getEntityManager()->refresh($attempt, LockMode::PESSIMISTIC_WRITE);
    }

    public function findPending(Cycle $cycle): ?Attempt
    {
        return $this->findOneBy(['cycle' => $cycle, 'status' => AttemptStatus::Pending]);
    }

    public function findPendingOfRun(Run $run): ?Attempt
    {
        return $this->findOneBy(['run' => $run, 'status' => AttemptStatus::Pending]);
    }

    /**
     * What a timed run resolved: solved, failed, active time (each attempt capped) and the rounds
     * it went through.
     *
     * @return array{solved: int, failed: int, activeMs: int, rounds: list<int>}
     */
    public function statsOfRun(Run $run): array
    {
        $connection = $this->getEntityManager()->getConnection();
        $row = $connection->fetchAssociative(
            "SELECT SUM(status = 'solved') AS solved, SUM(status = 'failed') AS failed,
                    SUM(CASE WHEN status != 'pending' THEN LEAST(COALESCE(duration_ms, 0), :cap) ELSE 0 END) AS active_ms
             FROM woodpecker_attempt WHERE training_run_id = :run",
            ['run' => $run->getId()->toBinary(), 'cap' => Attempt::ACTIVE_TIME_CAP_MS],
        ) ?: [];
        $rounds = $connection->fetchFirstColumn(
            "SELECT DISTINCT c.number FROM woodpecker_attempt a JOIN woodpecker_cycle c ON c.id = a.cycle_id
             WHERE a.training_run_id = :run AND a.status != 'pending' ORDER BY c.number",
            ['run' => $run->getId()->toBinary()],
        );

        return [
            'solved' => self::int($row['solved'] ?? 0),
            'failed' => self::int($row['failed'] ?? 0),
            'activeMs' => self::int($row['active_ms'] ?? 0),
            'rounds' => array_map(self::int(...), $rounds),
        ];
    }

    public function countResolved(Cycle $cycle): int
    {
        return (int) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->where('a.cycle = :cycle AND a.status != :pending')
            ->setParameter('cycle', $cycle->getId(), 'uuid')
            ->setParameter('pending', AttemptStatus::Pending)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Statistics of several runs in one grouped query.
     *
     * @param list<Cycle> $cycles
     *
     * @return array<string, CycleStats> by cycle id (RFC 4122); runs without attempts are absent
     */
    public function statsFor(array $cycles): array
    {
        if ([] === $cycles) {
            return [];
        }

        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            "SELECT cycle_id,
                    SUM(status = 'solved') AS solved,
                    SUM(status = 'failed') AS failed,
                    SUM(CASE WHEN status != 'pending' THEN LEAST(COALESCE(duration_ms, 0), :cap) ELSE 0 END) AS active_ms
             FROM woodpecker_attempt WHERE cycle_id IN (:ids) GROUP BY cycle_id",
            ['ids' => array_map(static fn (Cycle $c): string => $c->getId()->toBinary(), $cycles), 'cap' => Attempt::ACTIVE_TIME_CAP_MS],
            ['ids' => ArrayParameterType::BINARY],
        );

        $stats = [];
        foreach ($rows as $row) {
            $id = $row['cycle_id'];
            if (\is_string($id)) {
                $stats[Uuid::fromBinary($id)->toRfc4122()] = new CycleStats(self::int($row['solved']), self::int($row['failed']), self::int($row['active_ms']));
            }
        }

        return $stats;
    }

    /**
     * Puzzles failed in at least $minCycles distinct cycle numbers of the set (lost runs included).
     *
     * @return list<array{puzzleId: int, failedCycles: int}> most failed first
     */
    public function findStubborn(Set $set, int $minCycles = 2): array
    {
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            "SELECT a.puzzle_id, COUNT(DISTINCT c.number) AS failed_cycles
             FROM woodpecker_attempt a JOIN woodpecker_cycle c ON c.id = a.cycle_id
             WHERE c.set_id = :set AND a.status = 'failed'
             GROUP BY a.puzzle_id HAVING failed_cycles >= :min
             ORDER BY failed_cycles DESC, a.puzzle_id",
            ['set' => $set->getId()->toBinary(), 'min' => $minCycles],
        );

        return array_map(static fn (array $row): array => ['puzzleId' => self::int($row['puzzle_id']), 'failedCycles' => self::int($row['failed_cycles'])], $rows);
    }

    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
