<?php

declare(strict_types=1);

namespace App\Woodpecker\Cycle;

use App\Activity\Event\ExerciseCompleted;
use App\Activity\EventPublisher;
use App\Entity\Training\Run;
use App\Entity\User;
use App\Entity\Woodpecker\Attempt;
use App\Entity\Woodpecker\Cycle;
use App\Entity\Woodpecker\Set;
use App\Enum\Activity\ExerciseType;
use App\Enum\Woodpecker\CycleStatus;
use App\Enum\Woodpecker\SetMode;
use App\Enum\Woodpecker\SetStatus;
use App\Puzzle\Attempt\Submission;
use App\Puzzle\Solution\InvalidSubmissionException;
use App\Puzzle\Solution\SolutionValidator;
use App\Repository\Training\RunRepository;
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
use App\Woodpecker\Training\WoodpeckerModule;
use App\Puzzle\Catalog\PuzzleCatalog;
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
        private readonly PuzzleCatalog $catalog,
        private readonly EventPublisher $events,
        private readonly ProgressionRegistry $progressions,
        private readonly RunRepository $runs,
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
     * Untimed play: the pending attempt of the current round, or a new one on its next puzzle.
     * Not for a light set (timed runs only) nor for a set a timed run is holding.
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
            if (null !== $this->runs->findActiveOnSubject($user, WoodpeckerModule::SUBJECT_TYPE, $set->getId())) {
                throw new SetNotPlayableException(SetNotPlayableException::IN_RUN);
            }

            return $this->serve($set, null, $now);
        });
    }

    /**
     * The pending attempt of the current round, or a new one on its next puzzle. In a timed run, a
     * pending attempt served outside it (untimed play) is taken over with a fresh timer; one
     * already served in it comes back as is (reload, reconnection). Call inside a transaction, set
     * locked and refreshed.
     *
     * @throws SetNotPlayableException
     */
    public function serve(Set $set, ?Run $run, \DateTimeImmutable $now): Attempt
    {
        if (null === $run && SetMode::Light === $set->getMode()) {
            throw new SetNotPlayableException(SetNotPlayableException::TIMED_ONLY);
        }
        $cycle = $this->playableCycle($set, $now);

        $pending = $this->attempts->findPending($cycle);
        if (null !== $pending) {
            if (null !== $run && !$pending->belongsTo($run)) {
                $pending->assignTo($run, $now);
            }

            return $pending;
        }

        $index = $this->attempts->countResolved($cycle);
        $position = $this->progressions->for($set)->positionAt($set, $cycle, $index)
            ?? throw new \LogicException('Open cycle run with every puzzle played.');
        $setPuzzle = $this->setPuzzles->findAt($set, $position) ?? throw new \LogicException('Set list is incomplete.');

        $attempt = new Attempt($cycle, $setPuzzle->getPuzzleId(), $index, $now, $run);
        $this->entityManager->persist($attempt);

        return $attempt;
    }

    /**
     * Untimed play: resolves an attempt of the current round from the client's move log.
     *
     * @throws AttemptNotFoundException
     * @throws AttemptAlreadySubmittedException
     * @throws CycleClosedException
     * @throws SetNotPlayableException          the attempt belongs to a timed run
     * @throws InvalidSubmissionException
     */
    public function submit(User $user, Uuid $attemptId, Submission $submission): Attempt
    {
        return $this->entityManager->wrapInTransaction(function () use ($user, $attemptId, $submission): Attempt {
            $now = $this->now();
            $found = $this->attempts->findOwned($attemptId, $user) ?? throw new AttemptNotFoundException();
            $set = $this->sets->lockOwned($found->getCycle()->getSet()->getId(), $user) ?? throw new AttemptNotFoundException();
            $this->refresh($set, $now);

            return $this->resolve($set, $found, $submission, null, $now);
        });
    }

    /**
     * Resolves an attempt of the current round from the client's move log (outcome and duration
     * computed here), then lets the set's mode close the round and open the next one. The attempt
     * must belong to $run (null: untimed play). Call inside a transaction, set locked and
     * refreshed.
     *
     * @throws AttemptNotFoundException         not an attempt of this run
     * @throws AttemptAlreadySubmittedException
     * @throws CycleClosedException
     * @throws SetNotPlayableException          untimed submission of an attempt of a timed run
     * @throws InvalidSubmissionException
     */
    public function resolve(Set $set, Attempt $attempt, Submission $submission, ?Run $run, \DateTimeImmutable $now): Attempt
    {
        $this->attempts->lock($attempt);
        if (!$attempt->belongsTo($run)) {
            throw null === $run ? new SetNotPlayableException(SetNotPlayableException::IN_RUN) : new AttemptNotFoundException();
        }
        if (!$attempt->isPending()) {
            throw new AttemptAlreadySubmittedException();
        }
        $cycle = $attempt->getCycle();
        $this->entityManager->refresh($cycle);
        if (CycleStatus::Active !== $cycle->getStatus() || SetStatus::Active !== $set->getStatus()) {
            throw new CycleClosedException();
        }

        $puzzle = $this->catalog->get($attempt->getPuzzleId());
        $replay = $this->validator->replay($puzzle, $submission->moves);
        $solved = $replay->isClean() && 0 === $submission->hintLevel && !$submission->solutionShown;
        $attempt->resolve($solved, $submission->moves, $replay->mistakes, $submission->hintLevel, $submission->solutionShown, $now);
        $this->entityManager->flush();

        $metadata = [
            'setId' => $set->getId()->toRfc4122(),
            'cycle' => $cycle->getNumber(),
            'run' => $cycle->getRun(),
            'puzzleId' => $puzzle->getLichessId(),
            'mode' => $set->getMode()->value,
        ];
        if (null !== $run) {
            $metadata['trainingRunId'] = $run->getId()->toRfc4122();
        }
        $this->events->publish(new ExerciseCompleted(
            userId: $set->getUser()->getId()->toRfc4122(),
            type: ExerciseType::WoodpeckerPuzzle,
            success: $solved,
            durationMs: $attempt->getDurationMs() ?? 0,
            itemCount: 1,
            sourceType: self::SOURCE_TYPE,
            sourceId: $attempt->getId()->toRfc4122(),
            occurredAt: $now,
            metadata: $metadata,
        ));

        $this->progressions->for($set)->afterSubmission($set, $cycle, $now);
        $this->entityManager->flush();

        return $attempt;
    }

    /**
     * @throws SetNotPlayableException
     */
    private function playableCycle(Set $set, \DateTimeImmutable $now): Cycle
    {
        match ($set->getStatus()) {
            SetStatus::Paused => throw new SetNotPlayableException(SetNotPlayableException::PAUSED),
            SetStatus::Completed, SetStatus::Abandoned => throw new SetNotPlayableException(SetNotPlayableException::CLOSED),
            SetStatus::Active => null,
        };
        $progression = $this->progressions->for($set);
        $cycle = $this->cycles->findOpen($set)
            ?? $progression->openRound($set, $now)
            ?? throw new SetNotPlayableException(SetNotPlayableException::CLOSED);
        $progression->assertPlayable($set, $cycle);

        return $cycle;
    }

    private function now(): \DateTimeImmutable
    {
        return $this->clock->now()->setTimezone(new \DateTimeZone('UTC'));
    }
}
