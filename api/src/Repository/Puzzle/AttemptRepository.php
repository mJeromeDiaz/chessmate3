<?php

declare(strict_types=1);

namespace App\Repository\Puzzle;

use App\Entity\Puzzle\Attempt;
use App\Entity\Puzzle\Puzzle;
use App\Entity\Training\Run;
use App\Entity\User;
use App\Enum\Puzzle\AttemptStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\QueryBuilder;
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

    public function findPendingRated(User $user): ?Attempt
    {
        /** @var Attempt|null */
        return $this->createQueryBuilder('a')
            ->where('a.user = :user AND a.status = :pending AND a.rated = true')
            ->setParameter('user', $user->getId(), 'uuid')
            ->setParameter('pending', AttemptStatus::Pending)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return list<Attempt>
     */
    public function findPendingOfRun(Run $run): array
    {
        return $this->findBy(['trainingRun' => $run, 'status' => AttemptStatus::Pending]);
    }

    /**
     * What a timed run resolved: solved, failed, active time (each attempt capped) and the rating
     * before its first rated attempt and after its last.
     *
     * @return array{solved: int, failed: int, activeMs: int, ratingBefore: float|null, ratingAfter: float|null}
     */
    public function statsOfRun(Run $run): array
    {
        $connection = $this->getEntityManager()->getConnection();
        $row = $connection->fetchAssociative(
            "SELECT SUM(status = 'solved') AS solved, SUM(status = 'failed') AS failed,
                    SUM(CASE WHEN status != 'pending' THEN LEAST(COALESCE(duration_ms, 0), :cap) ELSE 0 END) AS active_ms
             FROM puzzle_attempt WHERE training_run_id = :run",
            ['run' => $run->getId()->toBinary(), 'cap' => Attempt::ACTIVE_TIME_CAP_MS],
            // An untyped :cap is bound as a string: LEAST() would then compare as strings.
            ['cap' => ParameterType::INTEGER],
        ) ?: [];
        // Rating changes have UUID v7 ids: their binary order is their order of creation.
        $first = $connection->fetchOne(
            'SELECT rc.rating_before FROM puzzle_attempt a JOIN puzzle_rating_change rc ON rc.id = a.rating_change_id
             WHERE a.training_run_id = :run ORDER BY rc.id LIMIT 1',
            ['run' => $run->getId()->toBinary()],
        );
        $last = $connection->fetchOne(
            'SELECT rc.rating_after FROM puzzle_attempt a JOIN puzzle_rating_change rc ON rc.id = a.rating_change_id
             WHERE a.training_run_id = :run ORDER BY rc.id DESC LIMIT 1',
            ['run' => $run->getId()->toBinary()],
        );

        return [
            'solved' => self::int($row['solved'] ?? 0),
            'failed' => self::int($row['failed'] ?? 0),
            'activeMs' => self::int($row['active_ms'] ?? 0),
            'ratingBefore' => is_numeric($first) ? (float) $first : null,
            'ratingAfter' => is_numeric($last) ? (float) $last : null,
        ];
    }

    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * Whether the puzzle is in the user's history (a resolved attempt: a pending one is not
     * replayable, its solution is still at stake).
     */
    public function hasAttempted(User $user, Puzzle $puzzle): bool
    {
        return null !== $this->createQueryBuilder('a')
            ->select('1')
            ->where('a.user = :user AND a.puzzle = :puzzle AND a.status != :pending')
            ->setParameter('user', $user->getId(), 'uuid')
            ->setParameter('puzzle', $puzzle->getId())
            ->setParameter('pending', AttemptStatus::Pending)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Which of these puzzles the user already played rated: one probe per id into the
     * (user_id, rated_puzzle_id) unique index, whatever the size of the user's history.
     *
     * @param list<int> $puzzleIds
     *
     * @return list<int>
     */
    public function findRatedPuzzleIds(User $user, array $puzzleIds): array
    {
        if ([] === $puzzleIds) {
            return [];
        }

        return array_map(static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0, $this->getEntityManager()->getConnection()->fetchFirstColumn(
            'SELECT rated_puzzle_id FROM puzzle_attempt WHERE user_id = :user AND rated_puzzle_id IN (:ids)',
            ['user' => $user->getId()->toBinary(), 'ids' => $puzzleIds],
            ['ids' => ArrayParameterType::INTEGER],
        ));
    }

    /**
     * Loads an attempt of this user under SELECT ... FOR UPDATE (inside a transaction), so two
     * concurrent submissions of the same attempt are serialised and the second sees it resolved.
     * Another user's attempt is reported as missing, never as forbidden.
     */
    public function findOwnedForUpdate(Uuid $id, User $user): ?Attempt
    {
        /** @var Attempt|null $attempt */
        $attempt = $this->createQueryBuilder('a')
            ->where('a.id = :id AND a.user = :user')
            ->setParameter('id', $id, 'uuid')
            ->setParameter('user', $user->getId(), 'uuid')
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getOneOrNullResult();

        if (null !== $attempt) {
            $this->getEntityManager()->refresh($attempt, LockMode::PESSIMISTIC_WRITE);
        }

        return $attempt;
    }

    /**
     * The user's resolved attempts, newest first, with their puzzle and rating change.
     */
    public function createHistoryQueryBuilder(User $user, ?AttemptStatus $status, ?string $themeKey): QueryBuilder
    {
        $qb = $this->createQueryBuilder('a')
            ->addSelect('p', 'rc')
            ->join('a.puzzle', 'p')
            ->leftJoin('a.ratingChange', 'rc')
            ->where('a.user = :user')
            ->setParameter('user', $user->getId(), 'uuid')
            ->orderBy('a.startedAt', 'DESC')
            ->addOrderBy('a.id', 'DESC');

        if (null !== $status) {
            $qb->andWhere('a.status = :status')->setParameter('status', $status);
        } else {
            $qb->andWhere('a.status != :pending')->setParameter('pending', AttemptStatus::Pending);
        }

        if (null !== $themeKey) {
            // Bounded by the user's own history (the user_id index), not by the puzzle table.
            $qb->andWhere('JSON_CONTAINS(p.themes, :theme) = 1')->setParameter('theme', json_encode($themeKey));
        }

        return $qb;
    }
}
