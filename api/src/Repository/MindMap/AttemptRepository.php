<?php

declare(strict_types=1);

namespace App\Repository\MindMap;

use App\Entity\MindMap\Attempt;
use App\Entity\Training\Run;
use App\Entity\User;
use App\Enum\MindMap\Capacity;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * The runs played for mind map steps.
 *
 * @extends ServiceEntityRepository<Attempt>
 */
class AttemptRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Attempt::class);
    }

    public function findOfRun(Run $run): ?Attempt
    {
        return $this->findOneBy(['run' => $run]);
    }

    public function findOwnedByRun(Uuid $runId, User $user): ?Attempt
    {
        return $this->findOneBy(['run' => $runId, 'user' => $user]);
    }

    /**
     * When the player last started a step of the path (docs/DASHBOARD.md: the dashboard proposes
     * the path played last), on `idx_mind_map_attempt_user_step`'s prefix.
     */
    public function lastStartedAt(User $user, Capacity $capacity): ?\DateTimeImmutable
    {
        /** @var string|null $at */
        $at = $this->createQueryBuilder('a')
            ->select('MAX(a.startedAt)')
            ->where('a.user = :user')
            ->andWhere('a.capacity = :capacity')
            ->setParameter('user', $user->getId(), 'uuid')
            ->setParameter('capacity', $capacity->value)
            ->getQuery()
            ->getSingleScalarResult();

        return null === $at ? null : new \DateTimeImmutable($at, new \DateTimeZone('UTC'));
    }
}
