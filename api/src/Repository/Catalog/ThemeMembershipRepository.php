<?php

declare(strict_types=1);

namespace App\Repository\Puzzle;

use App\Entity\Puzzle\ThemeMembership;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Reads go through {@see \App\Puzzle\Selection\PuzzleSelector} and writes through
 * {@see \App\Puzzle\Selection\SelectionRebuilder}, both in plain SQL (index seeks, bulk inserts).
 *
 * @extends ServiceEntityRepository<ThemeMembership>
 */
class ThemeMembershipRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ThemeMembership::class);
    }
}
