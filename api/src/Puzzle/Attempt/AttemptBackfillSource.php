<?php

declare(strict_types=1);

namespace App\Puzzle\Attempt;

use App\Activity\Backfill\SourceInterface;
use App\Entity\Puzzle\Attempt;
use App\Enum\Puzzle\AttemptStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Resolved Phase 2 attempts, in id order (UUID v7: creation order), keyset-paginated.
 */
final class AttemptBackfillSource implements SourceInterface
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function getName(): string
    {
        return AttemptEvents::SOURCE_TYPE;
    }

    public function batches(int $batchSize): iterable
    {
        $lastId = null;
        do {
            $qb = $this->entityManager->createQueryBuilder()
                ->select('a', 'p', 'u', 'rc')
                ->from(Attempt::class, 'a')
                ->join('a.puzzle', 'p')
                ->join('a.user', 'u')
                ->leftJoin('a.ratingChange', 'rc')
                ->where('a.status != :pending')
                ->setParameter('pending', AttemptStatus::Pending)
                ->orderBy('a.id', 'ASC')
                ->setMaxResults($batchSize);
            if (null !== $lastId) {
                $qb->andWhere('a.id > :last')->setParameter('last', $lastId, 'uuid');
            }

            /** @var list<Attempt> $attempts */
            $attempts = $qb->getQuery()->getResult();
            if ([] === $attempts) {
                return;
            }

            $lastId = end($attempts)->getId();
            yield array_map(AttemptEvents::completed(...), $attempts);
            $this->entityManager->clear();
        } while (\count($attempts) === $batchSize);
    }
}
