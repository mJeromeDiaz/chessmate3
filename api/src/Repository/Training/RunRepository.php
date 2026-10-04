<?php

declare(strict_types=1);

namespace App\Repository\Training;

use App\Entity\Training\Run;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Run>
 */
class RunRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Run::class);
    }

    /**
     * The user's run, or null (also for another user's run: never tell them apart).
     */
    public function findOwned(Uuid $id, User $user): ?Run
    {
        return $this->findOneBy(['id' => $id, 'user' => $user]);
    }

    /**
     * The user's run under SELECT ... FOR UPDATE (inside a transaction), always locked before its
     * subject: lock order run, then subject (set), then item (attempt).
     */
    public function lockOwned(Uuid $id, User $user): ?Run
    {
        $run = $this->findOwned($id, $user);
        if (null !== $run) {
            $this->getEntityManager()->refresh($run, LockMode::PESSIMISTIC_WRITE);
        }

        return $run;
    }

    /**
     * The active run: at most one, by the unique index on the generated active_user_id.
     */
    public function findActiveOf(User $user): ?Run
    {
        return $this->findOneBy(['activeUserId' => $user->getId()]);
    }

    /**
     * The active run, locked (inside a transaction).
     */
    public function lockActiveOf(User $user): ?Run
    {
        $run = $this->findActiveOf($user);
        if (null !== $run) {
            $this->getEntityManager()->refresh($run, LockMode::PESSIMISTIC_WRITE);
        }

        return null !== $run && $run->isActive() ? $run : null;
    }

    public function findActiveOnSubject(User $user, string $subjectType, Uuid $subjectId): ?Run
    {
        $run = $this->findActiveOf($user);

        return null !== $run && $run->getSubjectType() === $subjectType && $run->getSubjectId()->equals($subjectId) ? $run : null;
    }

    /**
     * @return list<Run> the closed runs of a subject, newest first
     */
    public function findClosedBySubject(string $subjectType, Uuid $subjectId, int $limit = 50): array
    {
        /** @var list<Run> */
        return $this->createQueryBuilder('r')
            ->where('r.subjectType = :type AND r.subjectId = :subject AND r.closedAt IS NOT NULL')
            ->setParameter('type', $subjectType)
            ->setParameter('subject', $subjectId, 'uuid')
            ->orderBy('r.startedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * The runs of a training session, oldest first.
     *
     * @return list<Run>
     */
    public function findByParent(Uuid $sessionId): array
    {
        return $this->findBy(['parentId' => $sessionId], ['startedAt' => 'ASC']);
    }
}
