<?php

declare(strict_types=1);

namespace App\Repository\Repertoire;

use App\Entity\Repertoire\AutoBuild;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<AutoBuild>
 */
class AutoBuildRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AutoBuild::class);
    }

    /** The user's build, not expired (another user's: null). */
    public function findOwned(Uuid $id, User $user, \DateTimeImmutable $now): ?AutoBuild
    {
        $build = $this->findOneBy(['id' => $id, 'user' => $user]);

        return null !== $build && $build->getExpiresAt() > $now ? $build : null;
    }

    /** Forgets the expired builds, and the user's previous one (one build per user). */
    public function purge(User $user, \DateTimeImmutable $now): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'DELETE FROM repertoire_auto_build WHERE expires_at <= ? OR user_id = ?',
            [$now->format('Y-m-d H:i:s'), $user->getId()->toBinary()],
        );
    }
}
