<?php

declare(strict_types=1);

namespace App\Repository\Gamification;

use App\Entity\Gamification\StreakNotice;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<StreakNotice>
 */
class StreakNoticeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, StreakNotice::class);
    }
}
