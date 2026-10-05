<?php

declare(strict_types=1);

namespace App\Repository\Gamification;

use App\Entity\Gamification\XpEntry;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<XpEntry>
 */
class XpEntryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, XpEntry::class);
    }

    /**
     * XP gained in a timed run so far (the gains are written by the worker: a review read right
     * after the run may not count them all yet).
     */
    public function sumForRun(Uuid $runId): int
    {
        $sum = $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT COALESCE(SUM(xp), 0) FROM gamification_xp_entry WHERE training_run_id = ?',
            [$runId->toBinary()],
            [ParameterType::BINARY],
        );

        return is_numeric($sum) ? (int) $sum : 0;
    }
}
