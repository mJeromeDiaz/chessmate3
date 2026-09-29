<?php

declare(strict_types=1);

namespace App\Puzzle\Attempt;

use App\Activity\EventPublisher;
use App\Entity\Puzzle\Attempt;
use App\Entity\Puzzle\Puzzle;
use App\Entity\Puzzle\RatingChange;
use App\Entity\User;
use App\Enum\Puzzle\RatingChangeReason;
use App\Puzzle\Attempt\Exception\AttemptAlreadySubmittedException;
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
     */
    public function start(User $user, SelectionCriteria $criteria): Attempt
    {
        return $this->entityManager->wrapInTransaction(function () use ($user, $criteria): Attempt {
            $rating = $this->ratings->lockForUser($user);

            $pending = $this->attempts->findPendingRated($user);
            if (null !== $pending) {
                return $pending;
            }

            $this->checkPolicies($user, true);

            $puzzleId = $this->selector->select($user, $rating->getRating(), $rating->getDeviation(), $criteria);
            $puzzle = null === $puzzleId ? null : $this->puzzles->find($puzzleId);
            if (null === $puzzle) {
                throw new NoPuzzleAvailableException();
            }

            $attempt = new Attempt($user, $puzzle, true, new \DateTimeImmutable());
            $this->entityManager->persist($attempt);

            return $attempt;
        });
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
     * @throws InvalidSubmissionException
     */
    public function submit(User $user, Uuid $attemptId, Submission $submission): Attempt
    {
        return $this->entityManager->wrapInTransaction(function () use ($user, $attemptId, $submission): Attempt {
            $rating = $this->ratings->lockForUser($user);

            $attempt = $this->attempts->findOwnedForUpdate($attemptId, $user);
            if (null === $attempt) {
                throw new AttemptNotFoundException();
            }
            if (!$attempt->isPending()) {
                throw new AttemptAlreadySubmittedException();
            }

            $puzzle = $attempt->getPuzzle();
            $replay = $this->validator->replay($puzzle, $submission->moves);
            $solved = $replay->isClean() && 0 === $submission->hintLevel && !$submission->solutionShown;
            $now = new \DateTimeImmutable();

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
        });
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
