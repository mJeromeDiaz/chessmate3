<?php

declare(strict_types=1);

namespace App\Repository\Repertoire;

use App\Entity\Repertoire\Pending;
use App\Entity\Repertoire\Repertoire;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Pending>
 */
class PendingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Pending::class);
    }

    /**
     * The repertoire's pending positions, the most popular reply first, then the shallowest.
     *
     * @return list<Pending>
     */
    public function findByRepertoire(Repertoire $repertoire): array
    {
        /** @var list<Pending> */
        return $this->createQueryBuilder('p')
            ->where('p.repertoire = :repertoire')
            ->setParameter('repertoire', $repertoire->getId(), 'uuid')
            ->orderBy('p.games', 'DESC')
            ->addOrderBy('p.createdAt', 'ASC')
            ->addOrderBy('p.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findInRepertoire(Uuid $id, Repertoire $repertoire): ?Pending
    {
        $pending = $this->find($id);

        return null !== $pending && $pending->getRepertoire()->getId()->equals($repertoire->getId()) ? $pending : null;
    }
}
