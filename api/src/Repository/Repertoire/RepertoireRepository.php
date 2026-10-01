<?php

declare(strict_types=1);

namespace App\Repository\Repertoire;

use App\Entity\Repertoire\Repertoire;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Repertoire>
 */
class RepertoireRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Repertoire::class);
    }

    /**
     * The user's repertoire, or null (also for another user's: never tell them apart).
     */
    public function findOwned(Uuid $id, User $user): ?Repertoire
    {
        return $this->findOneBy(['id' => $id, 'user' => $user]);
    }

    /**
     * The user's repertoire under SELECT ... FOR UPDATE (inside a transaction): serialises every
     * change of one repertoire. Always locked before its positions, moves and segments.
     */
    public function lockOwned(Uuid $id, User $user): ?Repertoire
    {
        $repertoire = $this->findOwned($id, $user);
        if (null !== $repertoire) {
            $this->getEntityManager()->refresh($repertoire, LockMode::PESSIMISTIC_WRITE);
        }

        return $repertoire;
    }

    /**
     * @return list<Repertoire> newest first
     */
    public function findByUser(User $user): array
    {
        return $this->findBy(['user' => $user], ['createdAt' => 'DESC']);
    }

    public function countByUser(User $user): int
    {
        return $this->count(['user' => $user]);
    }
}
