<?php

declare(strict_types=1);

namespace App\Repository\Gamification;

use App\Entity\Gamification\TrophyUnlock;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TrophyUnlock>
 */
class TrophyUnlockRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TrophyUnlock::class);
    }
}
