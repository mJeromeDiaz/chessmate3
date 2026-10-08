<?php

declare(strict_types=1);

namespace App\Repository\Woodpecker;

use App\Entity\Catalog\Puzzle;
use App\Entity\User;
use App\Entity\Woodpecker\Cycle;
use App\Entity\Woodpecker\Set;
use App\Entity\Woodpecker\SetPuzzle;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SetPuzzle>
 */
class SetPuzzleRepository extends ServiceEntityRepository
{
    private const INSERT_BATCH = 500;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SetPuzzle::class);
    }

    public function findAt(Set $set, int $position): ?SetPuzzle
    {
        return $this->findOneBy(['set' => $set, 'position' => $position]);
    }

    /**
     * Writes a set's list, or appends to it from $firstPosition, with multi-row INSERTs (up to
     * 1,500 rows: no entity per row).
     *
     * @param list<int> $puzzleIds in the set's order
     */
    public function insertList(Set $set, array $puzzleIds, int $firstPosition = 0): void
    {
        $connection = $this->getEntityManager()->getConnection();
        $setId = $set->getId()->toBinary();

        foreach (array_chunk($puzzleIds, self::INSERT_BATCH, true) as $chunk) {
            $values = [];
            $params = [];
            foreach ($chunk as $position => $puzzleId) {
                $values[] = '(?, ?, ?)';
                array_push($params, $setId, $firstPosition + $position, $puzzleId);
            }
            $connection->executeStatement(
                'INSERT INTO woodpecker_set_puzzle (set_id, position, puzzle_id) VALUES '.implode(', ', $values),
                $params,
            );
        }
    }

    /**
     * The position of a puzzle in the set's list, or null when it is not in it.
     */
    public function findPositionOf(Set $set, int $puzzleId): ?int
    {
        $position = $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT position FROM woodpecker_set_puzzle WHERE set_id = :set AND puzzle_id = :puzzle',
            ['set' => $set->getId()->toBinary(), 'puzzle' => $puzzleId],
        );

        return is_numeric($position) ? (int) $position : null;
    }

    /**
     * Puts another puzzle at a position of the set's list (raw SQL: a SetPuzzle already loaded in
     * this process keeps the old id, clear the entity manager before reading it again).
     */
    public function replaceAt(Set $set, int $position, int $puzzleId): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE woodpecker_set_puzzle SET puzzle_id = :puzzle WHERE set_id = :set AND position = :position',
            ['puzzle' => $puzzleId, 'set' => $set->getId()->toBinary(), 'position' => $position],
        );
    }

    /**
     * The set's list in its order, with how often each puzzle was played and failed (every cycle
     * run of the set, lost ones included).
     *
     * @return list<array{position: int, puzzleId: int, played: int, failed: int}>
     */
    public function findListWithStats(Set $set): array
    {
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            "SELECT sp.position, sp.puzzle_id,
                    COUNT(a.id) AS played, COALESCE(SUM(a.status = 'failed'), 0) AS failed
             FROM woodpecker_set_puzzle sp
             LEFT JOIN woodpecker_cycle c ON c.set_id = sp.set_id
             LEFT JOIN woodpecker_attempt a ON a.cycle_id = c.id AND a.puzzle_id = sp.puzzle_id AND a.status != 'pending'
             WHERE sp.set_id = :set
             GROUP BY sp.position, sp.puzzle_id
             ORDER BY sp.position",
            ['set' => $set->getId()->toBinary()],
        );

        return array_map(static fn (array $row): array => [
            'position' => self::int($row['position']),
            'puzzleId' => self::int($row['puzzle_id']),
            'played' => self::int($row['played']),
            'failed' => self::int($row['failed']),
        ], $rows);
    }

    /**
     * @return list<int> the set's puzzle ids
     */
    public function findPuzzleIds(Set $set): array
    {
        return array_map(static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0, $this->getEntityManager()->getConnection()->fetchFirstColumn(
            'SELECT puzzle_id FROM woodpecker_set_puzzle WHERE set_id = :set',
            ['set' => $set->getId()->toBinary()],
        ));
    }

    /**
     * How many of the set's puzzles have no attempt yet in this round (pending ones count as
     * seen): one scan of the set's list, one unique (cycle_id, puzzle_id) probe per puzzle.
     */
    public function countUnseen(Set $set, Cycle $round): int
    {
        $count = $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM woodpecker_set_puzzle sp
             LEFT JOIN woodpecker_attempt a ON a.cycle_id = :round AND a.puzzle_id = sp.puzzle_id
             WHERE sp.set_id = :set AND a.id IS NULL',
            ['set' => $set->getId()->toBinary(), 'round' => $round->getId()->toBinary()],
        );

        return is_numeric($count) ? (int) $count : 0;
    }

    /**
     * The position of the first puzzle of the round's order that has no attempt yet in it: the
     * set's order, or, shuffled, an order keyed by the round's seed (CRC32 of seed and position,
     * deterministic; puzzles appended later fall at random places among the remaining ones).
     */
    public function firstUnseenPosition(Set $set, Cycle $round): ?int
    {
        $params = ['set' => $set->getId()->toBinary(), 'round' => $round->getId()->toBinary()];
        $order = 'sp.position';
        if ($set->isShuffled()) {
            $order = "CRC32(CONCAT(:seed, ':', sp.position)), sp.position";
            $params['seed'] = $round->getSeed();
        }
        $position = $this->getEntityManager()->getConnection()->fetchOne(
            "SELECT sp.position FROM woodpecker_set_puzzle sp
             LEFT JOIN woodpecker_attempt a ON a.cycle_id = :round AND a.puzzle_id = sp.puzzle_id
             WHERE sp.set_id = :set AND a.id IS NULL
             ORDER BY {$order} LIMIT 1",
            $params,
        );

        return is_numeric($position) ? (int) $position : null;
    }

    /**
     * Which of these puzzles belong to one of the given sets (unique (set_id, puzzle_id) probes).
     *
     * @param list<string> $setIdsBinary
     * @param list<int>    $puzzleIds
     *
     * @return list<int>
     */
    public function findPuzzleIdsInSets(array $setIdsBinary, array $puzzleIds): array
    {
        if ([] === $setIdsBinary || [] === $puzzleIds) {
            return [];
        }

        return array_values(array_unique(array_map(static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0, $this->getEntityManager()->getConnection()->fetchFirstColumn(
            'SELECT puzzle_id FROM woodpecker_set_puzzle WHERE set_id IN (:sets) AND puzzle_id IN (:ids)',
            ['sets' => $setIdsBinary, 'ids' => $puzzleIds],
            ['sets' => ArrayParameterType::BINARY, 'ids' => ArrayParameterType::INTEGER],
        ))));
    }

    public function isInAnySetOf(User $user, Puzzle $puzzle): bool
    {
        return false !== $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT 1 FROM woodpecker_set_puzzle sp JOIN woodpecker_set s ON s.id = sp.set_id
             WHERE sp.puzzle_id = :puzzle AND s.user_id = :user LIMIT 1',
            ['puzzle' => $puzzle->getId(), 'user' => $user->getId()->toBinary()],
        );
    }

    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
