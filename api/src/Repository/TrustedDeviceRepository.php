<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\TrustedDevice;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TrustedDevice>
 */
class TrustedDeviceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TrustedDevice::class);
    }

    public function findOneByTokenHash(string $tokenHash): ?TrustedDevice
    {
        return $this->findOneBy(['tokenHash' => $tokenHash]);
    }

    public function save(TrustedDevice $device, bool $flush = true): void
    {
        $this->getEntityManager()->persist($device);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
