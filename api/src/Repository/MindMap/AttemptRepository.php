<?php

declare(strict_types=1);

namespace App\Repository\MindMap;

use App\Entity\MindMap\Attempt;
use App\Entity\Training\Run;
use App\Entity\User;
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
}
