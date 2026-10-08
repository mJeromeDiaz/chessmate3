<?php

declare(strict_types=1);

namespace App\Woodpecker\Set;

use App\Entity\User;
use App\Entity\Woodpecker\Set;
use App\Enum\Woodpecker\SetStatus;
use App\Repository\Catalog\PuzzleRepository;
use App\Repository\Catalog\ThemeRepository;
use App\Repository\Woodpecker\AttemptRepository;
use App\Repository\Woodpecker\CycleRepository;
use App\Repository\Woodpecker\SetPuzzleRepository;
use App\Repository\Woodpecker\SetRepository;
use App\Woodpecker\Cycle\CycleRunner;
use App\Woodpecker\Exception\NotEnoughPuzzlesException;
use App\Woodpecker\Exception\PuzzleNotInSetException;
use App\Woodpecker\Exception\SetNotFoundException;
use App\Woodpecker\Exception\SetNotPlayableException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Swaps a puzzle of an ongoing set for another one of the same profile (rating range, themes), at
 * the same position (docs/WOODPECKER.md, "Remplacer un puzzle"): the player shapes the set to
 * taste. The cycle order does not move; the attempts already played on the old puzzle stay in the
 * history and the statistics. A pending attempt on it (untimed or in a timed run) is dropped: the
 * next request serves the new puzzle in its place, with no failure counted.
 */
final class PuzzleReplacer
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SetRepository $sets,
        private readonly SetPuzzleRepository $setPuzzles,
        private readonly CycleRepository $cycles,
        private readonly AttemptRepository $attempts,
        private readonly PuzzleRepository $puzzles,
        private readonly ThemeRepository $themes,
        private readonly SetGenerator $generator,
        private readonly CycleRunner $runner,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @return array{position: int, puzzleId: int} the new puzzle and its position
     *
     * @throws SetNotFoundException
     * @throws SetNotPlayableException   the set is completed or abandoned
     * @throws PuzzleNotInSetException
     * @throws NotEnoughPuzzlesException no other puzzle of this profile left
     */
    public function replace(User $user, Uuid $setId, string $lichessId): array
    {
        // Read-only lookup in the catalogue, outside the transaction.
        $old = $this->puzzles->findOneByLichessId($lichessId);

        return $this->entityManager->wrapInTransaction(function () use ($user, $setId, $old): array {
            $now = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'));
            $set = $this->sets->lockOwned($setId, $user) ?? throw new SetNotFoundException();
            $this->runner->refresh($set, $now);
            if (!\in_array($set->getStatus(), [SetStatus::Active, SetStatus::Paused], true)) {
                throw new SetNotPlayableException(SetNotPlayableException::CLOSED);
            }
            $oldId = null !== $old ? (int) $old->getId() : null;
            $position = null !== $oldId ? $this->setPuzzles->findPositionOf($set, $oldId) : null;
            if (null === $oldId || null === $position) {
                throw new PuzzleNotInSetException();
            }

            $newId = $this->pick($set, $oldId);

            $this->setPuzzles->replaceAt($set, $position, $newId);
            $open = $this->cycles->findOpen($set);
            $pending = null !== $open ? $this->attempts->findPending($open) : null;
            if (null !== $pending && $pending->getPuzzleId() === $oldId) {
                $this->entityManager->remove($pending);
                $this->entityManager->flush();
            }

            return ['position' => $position, 'puzzleId' => $newId];
        });
    }

    /**
     * @throws NotEnoughPuzzlesException
     */
    private function pick(Set $set, int $oldId): int
    {
        $themeIds = array_map(static fn ($theme): int => (int) $theme->getId(), $this->themes->findByKeys($set->getThemes()));
        if ([] === $themeIds && [] !== $set->getThemes()) {
            // Themes gone since the creation: never widen to "any theme" silently.
            throw new NotEnoughPuzzlesException(0, 1);
        }
        $exclude = $this->setPuzzles->findPuzzleIds($set);
        $exclude[] = $oldId;

        return $this->generator->extend(1, $set->getRatingMin(), $set->getRatingMax(), $themeIds, $exclude)[0]
            ?? throw new NotEnoughPuzzlesException(0, 1);
    }
}
