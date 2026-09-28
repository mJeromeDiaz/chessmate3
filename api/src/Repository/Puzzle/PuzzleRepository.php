<?php

declare(strict_types=1);

namespace App\Repository\Puzzle;

use App\Entity\Puzzle\Puzzle;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
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

    public function findOneByLichessId(string $lichessId): ?Puzzle
    {
        return $this->findOneBy(['lichessId' => $lichessId]);
    }
}
