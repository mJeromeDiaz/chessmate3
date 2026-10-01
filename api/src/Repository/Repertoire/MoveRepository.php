<?php

declare(strict_types=1);

namespace App\Repository\Repertoire;

use App\Entity\Repertoire\Move;
use App\Entity\Repertoire\Position;
use App\Entity\Repertoire\Repertoire;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Move>
 */
class MoveRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Move::class);
    }

    public function findInRepertoire(Uuid $id, Repertoire $repertoire): ?Move
    {
        return $this->findOneBy(['id' => $id, 'repertoire' => $repertoire]);
    }

    public function findFrom(Position $from, string $uci): ?Move
    {
        return $this->findOneBy(['from' => $from, 'uci' => $uci]);
    }

    /**
     * Moves from a position, in display order.
     *
     * @return list<Move>
     */
    public function findSiblings(Position $from): array
    {
        return $this->findBy(['from' => $from], ['sortOrder' => 'ASC', 'id' => 'ASC']);
    }
}
