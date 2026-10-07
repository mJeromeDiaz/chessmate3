<?php

declare(strict_types=1);

namespace App\Repository\Gamification;

use App\Entity\Gamification\StreakReminder;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<StreakReminder>
 */
class StreakReminderRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, StreakReminder::class);
    }
}
