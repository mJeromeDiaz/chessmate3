<?php

declare(strict_types=1);

namespace App\Puzzle\Attempt;

use App\Activity\EventPublisher;
use App\Entity\Puzzle\Attempt;
use App\Entity\Puzzle\Puzzle;
use App\Entity\Puzzle\RatingChange;
use App\Entity\Training\Run;
use App\Entity\User;
use App\Enum\Puzzle\RatingChangeReason;
use App\Puzzle\Attempt\Exception\AttemptAlreadySubmittedException;
use App\Puzzle\Attempt\Exception\AttemptHeldByRunException;
use App\Puzzle\Attempt\Exception\AttemptNotFoundException;
use App\Puzzle\Attempt\Exception\NoPuzzleAvailableException;
use App\Puzzle\Attempt\Exception\ReplayNotAllowedException;
use App\Puzzle\Rating\RatingCalculator;
use App\Puzzle\Selection\PuzzleSelector;
use App\Puzzle\Selection\SelectionCriteria;
use App\Puzzle\Solution\InvalidSubmissionException;
use App\Puzzle\Solution\SolutionValidator;
use App\Repository\Puzzle\AttemptRepository;
use App\Repository\Puzzle\PuzzleRepository;
use App\Repository\Puzzle\RatingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\Uid\Uuid;

/**
 * Hands out puzzles and resolves attempts (docs/PUZZLES.md, "Rating rules").
 *
 * Every operation locks the user's rating row first (then the attempt), always in that order, so a
 * user's starts and submissions are serialised without deadlocks: one pending rated attempt at a
 * time, one submission per attempt, one rating update at a time.
 */
final class AttemptService
{
    /**
     * @param iterable<StartPolicyInterface>      $startPolicies
     * @param iterable<ReplayAuthorizerInterface> $replayAuthorizers
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly RatingRepository $ratings,
        private readonly AttemptRepository $attempts,
        private readonly PuzzleRepository $puzzles,
        private readonly PuzzleSelector $selector,
        private readonly SolutionValidator $validator,
        private readonly RatingCalculator $calculator,
        private readonly EventPublisher $events,
        #[AutowireIterator(StartPolicyInterface::TAG)]
        private readonly iterable $startPolicies,
        #[AutowireIterator(ReplayAuthorizerInterface::TAG)]
        private readonly iterable $replayAuthorizers = [],
    ) {
    }

    /**
     * Returns the user's pending rated attempt if there is one (reloading the page does not skip a
     * puzzle: validated rule), otherwise selects a new puzzle and starts a rated attempt.
     *
     * @throws NoPuzzleAvailableException
     * @throws AttemptHeldByRunException the pending attempt is being played in a timed run
     */
    public function start(User $user, SelectionCriteria $criteria): Attempt
    {
        return $this->entityManager->wrapInTransaction(fn (): Attempt => $this->serve($user, $criteria, null, new \DateTimeImmutable()));
    }

    /**
     * Same as {@see start()} for a timed run, inside the caller's transaction (the run locked
     * first): the user's pending rated attempt, whether started in free play or left by an earlier
     * run, joins this run and comes first; then new puzzles matching the run's criteria.
     *
     * @throws NoPuzzleAvailableException
     */
    public function startInRun(User $user, SelectionCriteria $criteria, Run $run, \DateTimeImmutable $now): Attempt
    {
        return $this->serve($user, $criteria, $run, $now);
    }

    /**
     * Starts an unrated attempt on a puzzle from the user's history (or one another domain
     * authorizes, {@see ReplayAuthorizerInterface}).
     *
     * @throws ReplayNotAllowedException
     */
    public function replay(User $user, Puzzle $puzzle): Attempt
    {
        return $this->entityManager->wrapInTransaction(function () use ($user, $puzzle): Attempt {
            $this->ratings->lockForUser($user);

            if (!$this->attempts->hasAttempted($user, $puzzle) && !$this->authorizedElsewhere($user, $puzzle)) {
                throw new ReplayNotAllowedException();
            }

            $this->checkPolicies($user, false);

            $attempt = new Attempt($user, $puzzle, false, new \DateTimeImmutable());
            $this->entityManager->persist($attempt);

            return $attempt;
        });
    }

    /**
     * Resolves an attempt from the client's move log. The outcome is computed here: solved only if
     * the solution was completed with no wrong move, no hint and without showing the solution.
     *
     * @throws AttemptNotFoundException
     * @throws AttemptAlreadySubmittedException
     * @throws AttemptHeldByRunException the attempt is being played in a timed run
     * @throws InvalidSubmissionException
     */
    public function submit(User $user, Uuid $attemptId, Submission $submission): Attempt
    {
        return $this->entityManager->wrapInTransaction(fn (): Attempt => $this->resolve($user, $attemptId, $submission, null, new \DateTimeImmutable()));
    }

    /**
     * Same as {@see submit()} for an attempt of this timed run, inside the caller's transaction.
     *
     * @throws AttemptNotFoundException not an attempt of this run
     * @throws AttemptAlreadySubmittedException
     * @throws InvalidSubmissionException
     */
    public function submitInRun(User $user, Uuid $attemptId, Submission $submission, Run $run, \DateTimeImmutable $now): Attempt
    {
        return $this->resolve($user, $attemptId, $submission, $run, $now);
    }

    /**
     * The run is closing: its pending attempt (the puzzle on screen) is not counted in the run but
     * stays the user's pending puzzle, so letting the time run out never skips a hard puzzle.
     */
    public function detachFromRun(Run $run): void
    {
        foreach ($this->attempts->findPendingOfRun($run) as $pending) {
            $pending->setTrainingRun(null);
        }
    }

    /**
     * @throws NoPuzzleAvailableException
     * @throws AttemptHeldByRunException
     */
    private function serve(User $user, SelectionCriteria $criteria, ?Run $run, \DateTimeImmutable $now): Attempt
    {
        $rating = $this->ratings->lockForUser($user);

        $pending = $this->attempts->findPendingRated($user);
        if (null !== $pending) {
            if (null === $run) {
                if (null !== $pending->getTrainingRun()) {
                    throw new AttemptHeldByRunException();
                }
            } else {
                $pending->setTrainingRun($run);
            }

            return $pending;
        }

        $this->checkPolicies($user, true);

        $puzzleId = $this->selector->select($user, $rating->getRating(), $rating->getDeviation(), $criteria);
        $puzzle = null === $puzzleId ? null : $this->puzzles->find($puzzleId);
        if (null === $puzzle) {
            throw new NoPuzzleAvailableException();
        }

        $attempt = new Attempt($user, $puzzle, true, $now);
        $attempt->setTrainingRun($run);
        $this->entityManager->persist($attempt);

        return $attempt;
    }

    /**
     * @throws AttemptNotFoundException
     * @throws AttemptAlreadySubmittedException
     * @throws AttemptHeldByRunException
     * @throws InvalidSubmissionException
     */
    private function resolve(User $user, Uuid $attemptId, Submission $submission, ?Run $run, \DateTimeImmutable $now): Attempt
    {
        $rating = $this->ratings->lockForUser($user);

        $attempt = $this->attempts->findOwnedForUpdate($attemptId, $user);
        if (null === $attempt) {
            throw new AttemptNotFoundException();
        }
        $heldBy = $attempt->getTrainingRun();
        if (null !== $run && (null === $heldBy || !$heldBy->getId()->equals($run->getId()))) {
            throw new AttemptNotFoundException();
        }
        if (!$attempt->isPending()) {
            throw new AttemptAlreadySubmittedException();
        }
        if (null === $run && null !== $heldBy) {
            throw new AttemptHeldByRunException();
        }

        $puzzle = $attempt->getPuzzle();
        $replay = $this->validator->replay($puzzle, $submission->moves);
        $solved = $replay->isClean() && 0 === $submission->hintLevel && !$submission->solutionShown;

        $change = null;
        if ($attempt->isRated()) {
            $before = $rating->getState();
            $after = $this->calculator->afterAttempt(
                $before,
                $rating->getLastRatedAt(),
                $now,
                $puzzle->getRating(),
                $puzzle->getRatingDeviation(),
                $solved,
            );
            $rating->recordAttempt($after, $now);
            $change = new RatingChange($user, RatingChangeReason::Attempt, $before, $after, $now);
            $this->entityManager->persist($change);
        }

        $attempt->resolve($solved, $submission->moves, $replay->mistakes, $submission->hintLevel, $submission->solutionShown, $now, $change);
        // Same transaction: the event exists if and only if the result is committed.
        $this->events->publish(AttemptEvents::completed($attempt));

        return $attempt;
    }

    private function authorizedElsewhere(User $user, Puzzle $puzzle): bool
    {
        foreach ($this->replayAuthorizers as $authorizer) {
            if ($authorizer->canReplay($user, $puzzle)) {
                return true;
            }
        }

        return false;
    }

    private function checkPolicies(User $user, bool $rated): void
    {
        foreach ($this->startPolicies as $policy) {
            $policy->check($user, $rated);
        }
    }
}
