<?php

declare(strict_types=1);

namespace App\Repository\Repertoire;

use App\Entity\Repertoire\Import;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Import>
 */
class ImportRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Import::class);
    }

    /** The user's import, not expired (another user's: null). */
    public function findOwned(Uuid $id, User $user, \DateTimeImmutable $now): ?Import
    {
        $import = $this->findOneBy(['id' => $id, 'user' => $user]);

        return null !== $import && $import->getExpiresAt() > $now ? $import : null;
    }

    /** Forgets the expired imports (lazy purge, at each new import). */
    public function purgeExpired(\DateTimeImmutable $now): int
    {
        return (int) $this->getEntityManager()->getConnection()->executeStatement(
            'DELETE FROM repertoire_import WHERE expires_at <= ?',
            [$now->format('Y-m-d H:i:s')],
        );
    }
}
