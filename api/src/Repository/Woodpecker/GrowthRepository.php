<?php

declare(strict_types=1);

namespace App\Repository\Woodpecker;

use App\Entity\Woodpecker\Growth;
use App\Entity\Woodpecker\Set;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Growth>
 */
class GrowthRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Growth::class);
    }

    /**
     * @return list<Growth> oldest first
     */
    public function findBySet(Set $set): array
    {
        return $this->findBy(['set' => $set], ['occurredAt' => 'ASC', 'id' => 'ASC']);
    }
}
