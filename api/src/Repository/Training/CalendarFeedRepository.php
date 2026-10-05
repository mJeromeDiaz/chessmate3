<?php

declare(strict_types=1);

namespace App\Repository\Training;

use App\Entity\Training\CalendarFeed;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CalendarFeed>
 */
class CalendarFeedRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CalendarFeed::class);
    }

    public function findForUser(User $user): ?CalendarFeed
    {
        return $this->findOneBy(['user' => $user]);
    }

    public function findByTokenHash(string $tokenHash): ?CalendarFeed
    {
        return $this->findOneBy(['tokenHash' => $tokenHash]);
    }
}
