<?php

declare(strict_types=1);

namespace App\Repository\Training;

use App\Entity\Training\Session;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Session>
 */
class SessionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Session::class);
    }

    public function findOwned(Uuid $id, User $user): ?Session
    {
        return $this->findOneBy(['id' => $id, 'user' => $user]);
    }

    /**
     * The user's session under SELECT ... FOR UPDATE (inside a transaction). Lock order: session,
     * then run (taken by {@see \App\Training\Run\TimeboxRunner} in its own transaction).
     */
    public function lockOwned(Uuid $id, User $user): ?Session
    {
        $session = $this->findOwned($id, $user);
        if (null !== $session) {
            $this->getEntityManager()->refresh($session, LockMode::PESSIMISTIC_WRITE);
        }

        return $session;
    }

    /**
     * The active session: at most one, by the unique index on the generated active_user_id.
     */
    public function findActiveOf(User $user): ?Session
    {
        return $this->findOneBy(['activeUserId' => $user->getId()]);
    }

    /**
     * @return list<Session> newest first
     */
    public function findRecentOf(User $user, int $limit): array
    {
        return $this->findBy(['user' => $user], ['startedAt' => 'DESC'], $limit);
    }
}
