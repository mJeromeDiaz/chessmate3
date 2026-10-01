<?php

declare(strict_types=1);

namespace App\Repository\Repertoire;

use App\Entity\Repertoire\Repertoire;
use App\Entity\Repertoire\TrashedSuite;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<TrashedSuite>
 */
class TrashedSuiteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TrashedSuite::class);
    }

    /**
     * @return list<TrashedSuite> newest first
     */
    public function findByRepertoire(Repertoire $repertoire): array
    {
        return $this->findBy(['repertoire' => $repertoire], ['createdAt' => 'DESC', 'id' => 'DESC']);
    }

    public function findInRepertoire(Uuid $id, Repertoire $repertoire): ?TrashedSuite
    {
        return $this->findOneBy(['id' => $id, 'repertoire' => $repertoire]);
    }

    public function countByRepertoire(Repertoire $repertoire): int
    {
        return $this->count(['repertoire' => $repertoire]);
    }
}
