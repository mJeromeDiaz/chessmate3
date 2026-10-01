<?php

declare(strict_types=1);

namespace App\Repository\Repertoire;

use App\Chess\Position\PositionKey;
use App\Entity\Repertoire\Opening;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Opening>
 */
class OpeningRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Opening::class);
    }

    public function findByKey(PositionKey $key): ?Opening
    {
        $opening = $this->findOneBy(['epdHash' => $key->hash]);

        return $opening?->getEpd() === $key->fen ? $opening : null;
    }
}
