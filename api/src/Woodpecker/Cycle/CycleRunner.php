<?php

declare(strict_types=1);

namespace App\Woodpecker\Cycle;

use App\Activity\Event\ExerciseCompleted;
use App\Activity\EventPublisher;
use App\Entity\User;
use App\Entity\Woodpecker\Attempt;
use App\Entity\Woodpecker\Cycle;
use App\Entity\Woodpecker\Set;
use App\Enum\Activity\ExerciseType;
use App\Enum\Woodpecker\CycleStatus;
use App\Enum\Woodpecker\SetStatus;
use App\Puzzle\Attempt\Submission;
use App\Puzzle\Solution\InvalidSubmissionException;
use App\Puzzle\Solution\SolutionValidator;
use App\Repository\Woodpecker\AttemptRepository;
use App\Repository\Woodpecker\CycleRepository;
use App\Repository\Woodpecker\SetPuzzleRepository;
use App\Repository\Woodpecker\SetRepository;
use App\Woodpecker\Exception\AttemptAlreadySubmittedException;
use App\Woodpecker\Exception\AttemptNotFoundException;
use App\Woodpecker\Exception\CycleClosedException;
use App\Woodpecker\Exception\SetNotFoundException;
use App\Woodpecker\Exception\SetNotPlayableException;
use App\Woodpecker\Mode\ProgressionRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Plays a set's rounds (docs/WOODPECKER.md, "Playing a cycle"): what every mode shares (locks,
 * attempts, server-side validation, ExerciseCompleted). How a round ends and what follows belongs
 * to the set's mode ({@see ProgressionRegistry}).
 *
 * Time-driven transitions (end of rest, lost run) are applied lazily by {@see self::refresh()} at
 * the start of every operation on the set, under its row lock: no scheduler is needed, and the
 * state a user sees is always up to date. Lock order: set, then attempt (as in Phase 2: no
 * deadlock between concurrent requests of the same user).
 */
final class CycleRunner
{
    public const SOURCE_TYPE = 'woodpecker_attempt';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SetRepository $sets,
        private readonly SetPuzzleRepository $setPuzzles,
        private readonly CycleRepository $cycles,
        private readonly AttemptRepository $attempts,
        private readonly SolutionValidator $validator,
        private readonly EventPublisher $events,
        private readonly ProgressionRegistry $progressions,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * Applies the transitions due at $now (see the set mode's progression). Paused and closed sets
     * do not move. Call inside a transaction, set locked.
     */
    public function refresh(Set $set, \DateTimeImmutable $now): void
    {
        if (SetStatus::Active !== $set->getStatus()) {
            return;
        }
        $cycle = $this->cycles->findOpen($set);
        if (null === $cycle) {
            return;
        }

        $this->progressions->for($set)->refresh($set, $cycle, $now);
        $this->entityManager->flush();
    }

    /**
     * The pending attempt of the current run, or a new one on the next puzzle of its order.
     *
     * @throws SetNotFoundException
     * @throws SetNotPlayableException
     */
    public function next(User $user, Uuid $setId): Attempt
    {
        return $this->entityManager->wrapInTransaction(function () use ($user, $setId): Attempt {
            $now = $this->now();
            $set = $this->sets->lockOwned($setId, $user) ?? throw new SetNotFoundException();
            $this->refresh($set, $now);
            $cycle = $this->playableCycle($set);

            $pending = $this->attempts->findPending($cycle);
            if (null !== $pending) {
                return $pending;
            }

            $index = $this->attempts->countResolved($cycle);
            $position = $this->progressions->for($set)->positionAt($set, $cycle, $index)
                ?? throw new \LogicException('Open cycle run with every puzzle played.');
            $setPuzzle = $this->setPuzzles->findAt($set, $position) ?? throw new \LogicException('Set list is incomplete.');

            $attempt = new Attempt($cycle, $setPuzzle->getPuzzle(), $index, $now);
            $this->entityManager->persist($attempt);

            return $attempt;
        });
    }

    /**
     * Resolves an attempt of the current run from the client's move log (outcome and duration
     * computed here), then closes the run and opens the next one when it was the last puzzle.
     *
     * @throws AttemptNotFoundException
     * @throws AttemptAlreadySubmittedException
     * @throws CycleClosedException
     * @throws InvalidSubmissionException
     */
    public function submit(User $user, Uuid $attemptId, Submission $submission): Attempt
    {
        return $this->entityManager->wrapInTransaction(function () use ($user, $attemptId, $submission): Attempt {
            $now = $this->now();
            $found = $this->attempts->findOwned($attemptId, $user) ?? throw new AttemptNotFoundException();
            $set = $this->sets->lockOwned($found->getCycle()->getSet()->getId(), $user) ?? throw new AttemptNotFoundException();
            $this->refresh($set, $now);

            $attempt = $found;
            $this->attempts->lock($attempt);
            if (!$attempt->isPending()) {
                throw new AttemptAlreadySubmittedException();
            }
            $cycle = $attempt->getCycle();
            $this->entityManager->refresh($cycle);
            if (CycleStatus::Active !== $cycle->getStatus() || SetStatus::Active !== $set->getStatus()) {
                throw new CycleClosedException();
            }

            $replay = $this->validator->replay($attempt->getPuzzle(), $submission->moves);
            $solved = $replay->isClean() && 0 === $submission->hintLevel && !$submission->solutionShown;
            $attempt->resolve($solved, $submission->moves, $replay->mistakes, $submission->hintLevel, $submission->solutionShown, $now);
            $this->entityManager->flush();

            $this->events->publish(new ExerciseCompleted(
                userId: $user->getId()->toRfc4122(),
                type: ExerciseType::WoodpeckerPuzzle,
                success: $solved,
                durationMs: $attempt->getDurationMs() ?? 0,
                itemCount: 1,
                sourceType: self::SOURCE_TYPE,
                sourceId: $attempt->getId()->toRfc4122(),
                occurredAt: $now,
                metadata: [
                    'setId' => $set->getId()->toRfc4122(),
                    'cycle' => $cycle->getNumber(),
                    'run' => $cycle->getRun(),
                    'puzzleId' => $attempt->getPuzzle()->getLichessId(),
                ],
            ));

            $this->progressions->for($set)->afterSubmission($set, $cycle, $now);
            $this->entityManager->flush();

            return $attempt;
        });
    }

    /**
     * @throws SetNotPlayableException
     */
    private function playableCycle(Set $set): Cycle
    {
        match ($set->getStatus()) {
            SetStatus::Paused => throw new SetNotPlayableException(SetNotPlayableException::PAUSED),
            SetStatus::Completed, SetStatus::Abandoned => throw new SetNotPlayableException(SetNotPlayableException::CLOSED),
            SetStatus::Active => null,
        };
        $cycle = $this->cycles->findOpen($set) ?? throw new SetNotPlayableException(SetNotPlayableException::CLOSED);
        $this->progressions->for($set)->assertPlayable($set, $cycle);

        return $cycle;
    }

    private function now(): \DateTimeImmutable
    {
        return $this->clock->now()->setTimezone(new \DateTimeZone('UTC'));
    }
}
