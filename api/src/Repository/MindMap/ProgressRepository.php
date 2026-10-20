<?php

declare(strict_types=1);

namespace App\Repository\MindMap;

use App\Entity\MindMap\Progress;
use App\Entity\User;
use App\Enum\MindMap\Capacity;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;

/**
 * The players' progress on the mind map paths; changed under {@see self::lockPathOf()}.
 *
 * @extends ServiceEntityRepository<Progress>
 */
class ProgressRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Progress::class);
    }

    /**
     * Serializes the changes of a player's paths until the transaction ends (a pass, a skip, a
     * chest): locks the player's row.
     */
    public function lockPathOf(User $user): void
    {
        $this->getEntityManager()->getConnection()->executeQuery(
            'SELECT id FROM app_user WHERE id = ? FOR UPDATE',
            [$user->getId()->toBinary()],
            [ParameterType::BINARY],
        );
    }

    /**
     * @return array<string, Progress> by step key
     */
    public function byStep(User $user, Capacity $capacity): array
    {
        $rows = [];
        foreach ($this->findBy(['user' => $user, 'capacity' => $capacity]) as $progress) {
            $rows[$progress->getStep()] = $progress;
        }

        return $rows;
    }
}
