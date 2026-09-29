<?php

declare(strict_types=1);

namespace App\Woodpecker\Mode;

use App\Entity\Woodpecker\Cycle;
use App\Entity\Woodpecker\Set;
use App\Enum\Woodpecker\SetMode;
use App\Repository\Woodpecker\AttemptRepository;
use App\Repository\Woodpecker\CycleRepository;
use App\Repository\Woodpecker\SetPuzzleRepository;
use App\Woodpecker\Cycle\CycleOrder;
use App\Woodpecker\Light\SetGrower;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Light mode (docs/WOODPECKER.md): no schedule. Each timed run plays one round from the first
 * puzzle of the set (or a fresh shuffle); a puzzle is never shown twice in a round. When the round
 * runs out of unseen puzzles, the set grows; at its maximum size (or pool exhausted), the round
 * closes and the next one starts at once.
 */
final class LightProgression implements ProgressionInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CycleRepository $cycles,
        private readonly SetPuzzleRepository $setPuzzles,
        private readonly AttemptRepository $attempts,
        private readonly SetGrower $grower,
    ) {
    }

    public function mode(): SetMode
    {
        return SetMode::Light;
    }

    /**
     * Nothing to open: a round starts with each run.
     */
    public function start(Set $set, \DateTimeImmutable $now): void
    {
    }

    public function openRound(Set $set, \DateTimeImmutable $now): Cycle
    {
        $round = new Cycle($set, $this->cycles->maxNumber($set) + 1, 1, null, CycleOrder::newSeed(), $now, null, $now);
        $this->entityManager->persist($round);

        return $round;
    }

    /**
     * Ends the open round, if any: the puzzle on screen (pending attempt) is dropped, not counted.
     * Called when a run ends; the next run opens a new round from the first puzzle.
     */
    public function endRound(Set $set, \DateTimeImmutable $now): void
    {
        $round = $this->cycles->findOpen($set);
        if (null === $round) {
            return;
        }
        $pending = $this->attempts->findPending($round);
        if (null !== $pending) {
            $this->entityManager->remove($pending);
        }
        $round->complete($now);
    }

    public function refresh(Set $set, Cycle $open, \DateTimeImmutable $now): void
    {
    }

    public function assertPlayable(Set $set, Cycle $open): void
    {
    }

    public function positionAt(Set $set, Cycle $round, int $index): ?int
    {
        return $this->setPuzzles->firstUnseenPosition($set, $round);
    }

    public function afterSubmission(Set $set, Cycle $round, \DateTimeImmutable $now): void
    {
        $this->grower->growIfNeeded($set, $round, $now);
        if (0 === $this->setPuzzles->countUnseen($set, $round)) {
            $round->complete($now);
            $this->openRound($set, $now);
        }
    }

    public function resume(Set $set, \DateTimeImmutable $pausedAt, \DateTimeImmutable $now): void
    {
    }
}
