<?php

declare(strict_types=1);

namespace App\Repository\Repertoire;

use App\Chess\Position\PositionKey;
use App\Entity\Repertoire\Position;
use App\Entity\Repertoire\Repertoire;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Position>
 */
class PositionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Position::class);
    }

    public function findInRepertoire(Uuid $id, Repertoire $repertoire): ?Position
    {
        return $this->findOneBy(['id' => $id, 'repertoire' => $repertoire]);
    }

    /**
     * The position with this normalized FEN, if the repertoire has it (digest lookup, FEN checked).
     */
    public function findByKey(Repertoire $repertoire, PositionKey $key): ?Position
    {
        $position = $this->findOneBy(['repertoire' => $repertoire, 'fenHash' => $key->hash]);

        return $position?->getFen() === $key->fen ? $position : null;
    }
}
