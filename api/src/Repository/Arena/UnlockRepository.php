<?php

declare(strict_types=1);

namespace App\Repository\Arena;

use App\Entity\Arena\Unlock;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;

/**
 * The bosses that arrived in the player's arena.
 *
 * @extends ServiceEntityRepository<Unlock>
 */
class UnlockRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Unlock::class);
    }

    /**
     * @return array<string, Unlock> by boss
     */
    public function byBoss(User $user): array
    {
        $unlocks = [];
        foreach ($this->findBy(['user' => $user], ['unlockedAt' => 'ASC', 'id' => 'ASC']) as $unlock) {
            $unlocks[$unlock->getBoss()] = $unlock;
        }

        return $unlocks;
    }

    /**
     * @return list<string> the bosses the player bought
     */
    public function purchased(User $user): array
    {
        /** @var list<string> */
        return $this->getEntityManager()->getConnection()->fetchFirstColumn(
            'SELECT boss FROM arena_unlock WHERE user_id = ? AND purchased_at IS NOT NULL',
            [$user->getId()->toBinary()],
            [ParameterType::BINARY],
        );
    }
}
