<?php

declare(strict_types=1);

namespace App\Woodpecker\Light;

use App\Activity\EventPublisher;
use App\Entity\Woodpecker\Cycle;
use App\Entity\Woodpecker\Growth;
use App\Entity\Woodpecker\Set;
use App\Repository\Catalog\ThemeRepository;
use App\Repository\Woodpecker\SetPuzzleRepository;
use App\Woodpecker\Event\SetGrown;
use App\Woodpecker\Set\SetGenerator;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Appends puzzles to a light set whose round is running out of unseen ones ({@see GrowthPolicy}):
 * same profile (rating range, themes), no duplicate, at the end of the list (existing positions
 * never move). Runs inside the submission's transaction, set locked: the generation is a few
 * index range scans (a few ms), no other lock held.
 */
final class SetGrower
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SetPuzzleRepository $setPuzzles,
        private readonly ThemeRepository $themes,
        private readonly SetGenerator $generator,
        private readonly GrowthPolicy $policy,
        private readonly EventPublisher $events,
    ) {
    }

    /**
     * @return int puzzles added (0: enough unseen, maximum size reached or pool exhausted)
     */
    public function growIfNeeded(Set $set, Cycle $round, \DateTimeImmutable $now): int
    {
        $batch = $this->policy->batchFor($set->getPuzzleCount(), $this->setPuzzles->countUnseen($set, $round));
        if (0 === $batch) {
            return 0;
        }

        $themeIds = array_map(static fn ($theme): int => (int) $theme->getId(), $this->themes->findByKeys($set->getThemes()));
        if ([] === $themeIds && [] !== $set->getThemes()) {
            return 0; // Themes gone since the creation: never widen to "any theme" silently.
        }
        $puzzleIds = $this->generator->extend($batch, $set->getRatingMin(), $set->getRatingMax(), $themeIds, $this->setPuzzles->findPuzzleIds($set));
        if ([] === $puzzleIds) {
            return 0;
        }

        $this->setPuzzles->insertList($set, $puzzleIds, $set->getPuzzleCount());
        $set->grow(\count($puzzleIds));
        $this->entityManager->persist(new Growth($set, $round->getNumber(), \count($puzzleIds), $set->getPuzzleCount(), $now));
        $this->events->publish(new SetGrown(
            userId: $set->getUser()->getId()->toRfc4122(),
            setId: $set->getId()->toRfc4122(),
            round: $round->getNumber(),
            added: \count($puzzleIds),
            puzzleCount: $set->getPuzzleCount(),
            occurredAt: $now,
        ));

        return \count($puzzleIds);
    }
}
