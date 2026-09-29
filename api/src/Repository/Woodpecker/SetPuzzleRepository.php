<?php

declare(strict_types=1);

namespace App\Repository\Woodpecker;

use App\Entity\Puzzle\Puzzle;
use App\Entity\User;
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
     * Writes a set's frozen list with multi-row INSERTs (up to 1,500 rows: no entity per row).
     *
     * @param list<int> $puzzleIds in the set's order
     */
    public function insertList(Set $set, array $puzzleIds): void
    {
        $connection = $this->getEntityManager()->getConnection();
        $setId = $set->getId()->toBinary();

        foreach (array_chunk($puzzleIds, self::INSERT_BATCH, true) as $chunk) {
            $values = [];
            $params = [];
            foreach ($chunk as $position => $puzzleId) {
                $values[] = '(?, ?, ?)';
                array_push($params, $setId, $position, $puzzleId);
            }
            $connection->executeStatement(
                'INSERT INTO woodpecker_set_puzzle (set_id, position, puzzle_id) VALUES '.implode(', ', $values),
                $params,
            );
        }
    }

    /**
     * Which of these puzzles belong to the given set (unique (set_id, puzzle_id) probes).
     *
     * @param list<int> $puzzleIds
     *
     * @return list<int>
     */
    public function findPuzzleIdsInSet(string $setIdBinary, array $puzzleIds): array
    {
        if ([] === $puzzleIds) {
            return [];
        }

        return array_map(static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0, $this->getEntityManager()->getConnection()->fetchFirstColumn(
            'SELECT puzzle_id FROM woodpecker_set_puzzle WHERE set_id = :set AND puzzle_id IN (:ids)',
            ['set' => $setIdBinary, 'ids' => $puzzleIds],
            ['ids' => ArrayParameterType::INTEGER],
        ));
    }

    public function isInAnySetOf(User $user, Puzzle $puzzle): bool
    {
        return false !== $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT 1 FROM woodpecker_set_puzzle sp JOIN woodpecker_set s ON s.id = sp.set_id
             WHERE sp.puzzle_id = :puzzle AND s.user_id = :user LIMIT 1',
            ['puzzle' => $puzzle->getId(), 'user' => $user->getId()->toBinary()],
        );
    }
}
