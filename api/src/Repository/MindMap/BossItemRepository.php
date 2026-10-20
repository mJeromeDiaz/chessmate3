<?php

declare(strict_types=1);

namespace App\Repository\MindMap;

use App\Entity\MindMap\BossItem;
use App\Entity\Training\Run;
use App\Entity\User;
use App\Enum\MindMap\BossItemStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * The questions of the boss fights, only changed under their run's lock (the run is locked first).
 *
 * @extends ServiceEntityRepository<BossItem>
 */
class BossItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BossItem::class);
    }

    public function findOwned(Uuid $id, User $user): ?BossItem
    {
        return $this->findOneBy(['id' => $id, 'user' => $user]);
    }

    public function findPendingOfRun(Run $run): ?BossItem
    {
        return $this->findOneBy(['run' => $run, 'status' => BossItemStatus::Pending]);
    }

    /**
     * @return list<BossItem> in the order asked, the pending one included
     */
    public function findOfRun(Run $run): array
    {
        /** @var list<BossItem> */
        return $this->createQueryBuilder('b')
            ->andWhere('b.run = :run')
            ->setParameter('run', $run->getId(), 'uuid')
            ->orderBy('b.startedAt', 'ASC')
            ->addOrderBy('b.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * The run's questions answered, by verdict (a late one is also wrong).
     *
     * @return array{right: int, wrong: int, late: int}
     */
    public function statsOfRun(Run $run): array
    {
        $stats = ['right' => 0, 'wrong' => 0, 'late' => 0];
        foreach ($this->findOfRun($run) as $item) {
            $status = $item->getStatus();
            if (BossItemStatus::Right === $status) {
                ++$stats['right'];
            } elseif (BossItemStatus::Pending !== $status) {
                ++$stats['wrong'];
                $stats['late'] += BossItemStatus::Late === $status ? 1 : 0;
            }
        }

        return $stats;
    }
}
