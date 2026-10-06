<?php

declare(strict_types=1);

namespace App\Puzzle\Catalog;

use App\Entity\Catalog\Puzzle;
use App\Repository\Catalog\PuzzleRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The puzzles behind the ids that attempts and Woodpecker sets keep (docs/DEPLOY_OVH.md, § 3): the
 * catalogue lives in another database, so no entity of the application links to a Puzzle. Batch
 * loads ({@see self::byIds()}) keep a list to one query.
 */
final readonly class PuzzleCatalog
{
    public function __construct(
        private PuzzleRepository $puzzles,
        #[Autowire(service: 'doctrine.orm.catalog_entity_manager')]
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function find(int $id): ?Puzzle
    {
        return $this->puzzles->find($id);
    }

    /**
     * @throws \RuntimeException the puzzle is missing from the catalogue (a re-import never deletes one)
     */
    public function get(int $id): Puzzle
    {
        return $this->puzzles->find($id)
            ?? throw new \RuntimeException(\sprintf('Puzzle %d is missing from the catalogue.', $id));
    }

    /**
     * @param iterable<int> $ids
     *
     * @return array<int, Puzzle> by id; a missing puzzle is left out
     */
    public function byIds(iterable $ids): array
    {
        $ids = array_values(array_unique([...$ids]));
        if ([] === $ids) {
            return [];
        }
        $byId = [];
        foreach ($this->puzzles->findBy(['id' => $ids]) as $puzzle) {
            $byId[(int) $puzzle->getId()] = $puzzle;
        }

        return $byId;
    }

    /**
     * @param iterable<int> $ids
     *
     * @return array<int, string> Lichess ids by id
     */
    public function lichessIds(iterable $ids): array
    {
        return $this->puzzles->findLichessIds([...$ids]);
    }

    /**
     * Detaches the puzzles loaded so far: batch jobs call it with their own `clear()`, which only
     * reaches the main database's entity manager.
     */
    public function clear(): void
    {
        $this->entityManager->clear();
    }
}
