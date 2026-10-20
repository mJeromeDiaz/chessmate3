<?php

declare(strict_types=1);

namespace App\Repository\MindMap;

use App\Entity\MindMap\Chest;
use App\Entity\User;
use App\Enum\MindMap\Capacity;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * The chests of the mind map paths.
 *
 * @extends ServiceEntityRepository<Chest>
 */
class ChestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Chest::class);
    }

    public function findOwned(Uuid $id, User $user): ?Chest
    {
        return $this->findOneBy(['id' => $id, 'user' => $user]);
    }

    /**
     * @return array<string, Chest> by step key
     */
    public function byStep(User $user, Capacity $capacity): array
    {
        $chests = [];
        foreach ($this->findBy(['user' => $user, 'capacity' => $capacity]) as $chest) {
            $chests[$chest->getStep()] = $chest;
        }

        return $chests;
    }
}
