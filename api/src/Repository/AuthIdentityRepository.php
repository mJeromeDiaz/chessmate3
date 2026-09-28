<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AuthIdentity;
use App\Enum\AuthProvider;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AuthIdentity>
 */
class AuthIdentityRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AuthIdentity::class);
    }

    public function findOneByProviderAndUserId(AuthProvider $provider, string $providerUserId): ?AuthIdentity
    {
        return $this->findOneBy(['provider' => $provider, 'providerUserId' => $providerUserId]);
    }

    public function save(AuthIdentity $authIdentity, bool $flush = true): void
    {
        $this->getEntityManager()->persist($authIdentity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
