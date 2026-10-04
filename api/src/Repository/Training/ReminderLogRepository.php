<?php

declare(strict_types=1);

namespace App\Repository\Training;

use App\Entity\Training\Plan;
use App\Entity\Training\ReminderLog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ReminderLog>
 */
class ReminderLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ReminderLog::class);
    }

    /**
     * Records the reminder of this occurrence, unless it already is: true for the one caller that
     * must send it. A plain INSERT IGNORE: a duplicate never throws (which would close the entity
     * manager) and two overlapping runs cannot both claim it.
     */
    public function claim(Plan $plan, \DateTimeImmutable $occursAt, \DateTimeImmutable $now): bool
    {
        return 1 === $this->getEntityManager()->getConnection()->executeStatement(
            'INSERT IGNORE INTO training_reminder_log (plan_id, occurs_at, sent_at) VALUES (?, ?, ?)',
            [$plan->getId()->toBinary(), $occursAt->format('Y-m-d H:i:s'), $now->format('Y-m-d H:i:s')],
        );
    }
}
