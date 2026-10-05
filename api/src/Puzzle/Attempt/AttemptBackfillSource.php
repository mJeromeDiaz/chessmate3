<?php

declare(strict_types=1);

namespace App\Puzzle\Attempt;

use App\Activity\Backfill\SourceInterface;
use App\Entity\Puzzle\Attempt;
use App\Enum\Puzzle\AttemptStatus;
use App\Puzzle\Catalog\PuzzleCatalog;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Resolved Phase 2 attempts, in id order (UUID v7: creation order), keyset-paginated.
 */
final class AttemptBackfillSource implements SourceInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PuzzleCatalog $catalog,
    ) {
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
                ->select('a', 'u', 'rc')
                ->from(Attempt::class, 'a')
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
            // The batch's puzzles in one query (they live in the catalogue).
            $puzzles = $this->catalog->byIds(array_map(static fn (Attempt $attempt): int => $attempt->getPuzzleId(), $attempts));
            yield array_map(
                fn (Attempt $attempt) => AttemptEvents::completed($attempt, $puzzles[$attempt->getPuzzleId()] ?? $this->catalog->get($attempt->getPuzzleId())),
                $attempts,
            );
            $this->entityManager->clear();
        } while (\count($attempts) === $batchSize);
    }
}
