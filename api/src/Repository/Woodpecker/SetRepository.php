<?php

declare(strict_types=1);

namespace App\Repository\Woodpecker;

use App\Entity\User;
use App\Entity\Woodpecker\Set;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Set>
 */
class SetRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Set::class);
    }

    /**
     * The user's set, or null (also for another user's set: never tell them apart).
     */
    public function findOwned(Uuid $id, User $user): ?Set
    {
        return $this->findOneBy(['id' => $id, 'user' => $user]);
    }

    /**
     * The user's set under SELECT ... FOR UPDATE (inside a transaction): serialises everything done
     * on one set. Always locked before any of its attempts.
     */
    public function lockOwned(Uuid $id, User $user): ?Set
    {
        $set = $this->findOwned($id, $user);
        if (null !== $set) {
            $this->getEntityManager()->refresh($set, LockMode::PESSIMISTIC_WRITE);
        }

        return $set;
    }

    /**
     * The active or paused set: at most one, by the unique index on the generated active_user_id.
     */
    public function findOngoing(User $user): ?Set
    {
        $id = $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT id FROM woodpecker_set WHERE active_user_id = :user',
            ['user' => $user->getId()->toBinary()],
        );

        return \is_string($id) ? $this->find(Uuid::fromBinary($id)) : null;
    }

    /**
     * @return list<Set> newest first
     */
    public function findByUser(User $user, bool $archived): array
    {
        /** @var list<Set> */
        return $this->createQueryBuilder('s')
            ->where('s.user = :user')
            ->andWhere($archived ? 's.archivedAt IS NOT NULL' : 's.archivedAt IS NULL')
            ->setParameter('user', $user->getId(), 'uuid')
            ->orderBy('s.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
