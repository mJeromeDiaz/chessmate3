<?php

declare(strict_types=1);

namespace App\Repository\Catalog;

use App\Entity\Catalog\Puzzle;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Puzzle>
 */
class PuzzleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Puzzle::class);
    }

    /**
     * Lichess ids of these puzzles, without loading them (exports: thousands of ids).
     *
     * @param array<int> $ids
     *
     * @return array<int, string> by id
     */
    public function findLichessIds(array $ids): array
    {
        $byId = [];
        foreach (array_chunk(array_values(array_unique($ids)), 1000) as $chunk) {
            $rows = $this->getEntityManager()->getConnection()->fetchAllKeyValue(
                'SELECT id, lichess_id FROM puzzle WHERE id IN (:ids)',
                ['ids' => $chunk],
                ['ids' => ArrayParameterType::INTEGER],
            );
            foreach ($rows as $id => $lichessId) {
                if (\is_string($lichessId)) {
                    $byId[(int) $id] = $lichessId;
                }
            }
        }

        return $byId;
    }

    public function findOneByLichessId(string $lichessId): ?Puzzle
    {
        return $this->findOneBy(['lichessId' => $lichessId]);
    }
}
