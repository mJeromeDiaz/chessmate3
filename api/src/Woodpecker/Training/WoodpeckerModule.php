<?php

declare(strict_types=1);

namespace App\Woodpecker\Training;

use App\ApiResource\Puzzle\PuzzleView;
use App\Entity\Training\Run;
use App\Entity\User;
use App\Entity\Woodpecker\Attempt;
use App\Entity\Woodpecker\Set;
use App\Enum\Puzzle\AttemptStatus;
use App\Enum\Training\CloseReason;
use App\Enum\Training\Module;
use App\Enum\Woodpecker\CycleStatus;
use App\Enum\Woodpecker\SetMode;
use App\Enum\Woodpecker\SetStatus;
use App\Puzzle\Attempt\Submission;
use App\Puzzle\Solution\InvalidSubmissionException;
use App\Repository\Woodpecker\AttemptRepository;
use App\Repository\Woodpecker\CycleRepository;
use App\Repository\Woodpecker\GrowthRepository;
use App\Repository\Woodpecker\SetRepository;
use App\Training\Exception\InvalidItemSubmissionException;
use App\Training\Exception\InvalidRunConfigException;
use App\Training\Exception\ItemAlreadySubmittedException;
use App\Training\Exception\ItemClosedException;
use App\Training\Exception\ItemNotFoundException;
use App\Training\Exception\SubjectNotFoundException;
use App\Training\Exception\SubjectUnavailableException;
use App\Training\Module\Item;
use App\Training\Module\ItemResult;
use App\Training\Module\ItemSubmission;
use App\Training\Module\PreparedStep;
use App\Training\Module\ReviewItem;
use App\Training\Module\ReviewableModuleInterface;
use App\Training\Module\Summary;
use App\Training\Module\TimeboxedModuleInterface;
use App\Woodpecker\Cycle\CycleRunner;
use App\Woodpecker\Exception\AttemptAlreadySubmittedException;
use App\Woodpecker\Exception\AttemptNotFoundException;
use App\Woodpecker\Exception\CycleClosedException;
use App\Woodpecker\Exception\SetNotPlayableException;
use App\Woodpecker\Mode\LightProgression;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Woodpecker in timed runs (docs/TRAINING.md, docs/WOODPECKER.md), both modes:
 *
 * - light: each run plays a new round from the first puzzle of the set (or a fresh shuffle);
 * - classic: a run moves the current cycle on, deadlines included; it closes when the cycle is
 *   completed and the next one rests (subject_resting), when the set is completed
 *   (subject_finished), or when the set is paused or abandoned meanwhile (subject_unavailable).
 *   A paused, closed or resting set cannot start a run.
 */
final class WoodpeckerModule implements TimeboxedModuleInterface, ReviewableModuleInterface
{
    public const SUBJECT_TYPE = 'woodpecker_set';
    public const ITEM_TYPE = 'woodpecker_puzzle';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SetRepository $sets,
        private readonly CycleRepository $cycles,
        private readonly AttemptRepository $attempts,
        private readonly GrowthRepository $growths,
        private readonly CycleRunner $runner,
        private readonly LightProgression $light,
    ) {
    }

    public function module(): Module
    {
        return Module::Woodpecker;
    }

    public function subjectType(): string
    {
        return self::SUBJECT_TYPE;
    }

    /**
     * A session step plays the user's ongoing light set (no settings): the one ongoing when the
     * step starts.
     */
    public function prepare(User $user, array $settings, string $notes): PreparedStep
    {
        if ([] !== $settings) {
            throw new InvalidRunConfigException('A Woodpecker step has no settings.');
        }
        $set = $this->sets->findOngoing($user, SetMode::Light)
            ?? throw new SubjectUnavailableException(CloseReason::SubjectUnavailable, ['reason' => 'no_light_set'], 'No light set in progress.');
        if (SetStatus::Active !== $set->getStatus()) {
            throw new SubjectUnavailableException(CloseReason::SubjectUnavailable, ['reason' => 'light_set_paused', 'setStatus' => $set->getStatus()->value], 'The light set is paused.');
        }

        return new PreparedStep($set->getId(), []);
    }

    public function start(Run $run, \DateTimeImmutable $now): void
    {
        $set = $this->lockSet($run) ?? throw new SubjectNotFoundException();
        $this->runner->refresh($set, $now);
        $this->assertPlayable($set);
        if (SetMode::Light === $set->getMode()) {
            $this->light->endRound($set, $now);
            $this->entityManager->flush();
            $this->light->openRound($set, $now);
        }
    }

    public function next(Run $run, \DateTimeImmutable $now): Item
    {
        $set = $this->lockSet($run) ?? throw new SubjectUnavailableException(CloseReason::SubjectUnavailable);
        $this->runner->refresh($set, $now);
        $this->assertPlayable($set);

        try {
            $attempt = $this->runner->serve($set, $run, $now);
        } catch (SetNotPlayableException) {
            $this->assertPlayable($set);
            throw new SubjectUnavailableException(CloseReason::SubjectUnavailable);
        }

        return $this->item($attempt, $set);
    }

    public function submit(Run $run, ItemSubmission $submission, \DateTimeImmutable $now): ItemResult
    {
        $set = $this->lockSet($run) ?? throw new SubjectUnavailableException(CloseReason::SubjectUnavailable);
        $this->runner->refresh($set, $now);
        $found = Uuid::isValid($submission->itemId) ? $this->attempts->findOwned(Uuid::fromString($submission->itemId), $run->getUser()) : null;
        if (null === $found || !$found->getCycle()->getSet()->getId()->equals($set->getId())) {
            throw new ItemNotFoundException();
        }
        if (SetStatus::Active !== $set->getStatus()) {
            throw new SubjectUnavailableException(CloseReason::SubjectUnavailable, ['setStatus' => $set->getStatus()->value]);
        }

        try {
            $attempt = $this->runner->resolve($set, $found, new Submission($submission->moves, $submission->hintLevel, $submission->solutionShown), $run, $now);
        } catch (AttemptNotFoundException) {
            throw new ItemNotFoundException();
        } catch (AttemptAlreadySubmittedException) {
            throw new ItemAlreadySubmittedException();
        } catch (CycleClosedException) {
            // The round ended under it (a classic deadline passed): the run goes on with the new one.
            throw new ItemClosedException();
        } catch (InvalidSubmissionException $e) {
            throw new InvalidItemSubmissionException($e->getMessage(), 0, $e);
        }

        [$closes, $context] = $this->afterSubmission($set);

        return new ItemResult(
            itemId: $attempt->getId()->toRfc4122(),
            success: AttemptStatus::Solved === $attempt->getStatus(),
            data: [
                'status' => $attempt->getStatus()->value,
                'mistakes' => $attempt->getMistakes(),
                'durationMs' => $attempt->getDurationMs(),
                'puzzleCount' => $set->getPuzzleCount(),
            ],
            closes: $closes,
            context: $context,
        );
    }

    public function close(Run $run, CloseReason $reason, \DateTimeImmutable $now): void
    {
        $set = $this->lockSet($run);
        // The puzzle on screen, and one left in a classic cycle run lost during the run.
        foreach ($this->attempts->findPendingOfRun($run) as $pending) {
            $this->entityManager->remove($pending);
        }
        $this->entityManager->flush();
        if (null !== $set && SetMode::Light === $set->getMode()) {
            $this->light->endRound($set, $now);
        }
    }

    public function summarize(Run $run, \DateTimeImmutable $closedAt): Summary
    {
        $stats = $this->attempts->statsOfRun($run);
        $played = $stats['solved'] + $stats['failed'];
        $set = $this->sets->find($run->getSubjectId());
        $metrics = [
            'mode' => $set?->getMode()->value,
            'solved' => $stats['solved'],
            'failed' => $stats['failed'],
            'activeMs' => $stats['activeMs'],
            'averageMs' => $played > 0 ? intdiv($stats['activeMs'], $played) : null,
            'rounds' => $stats['rounds'],
            'puzzleCount' => $set?->getPuzzleCount(),
            'added' => null === $set ? 0 : $this->growths->sumAddedSince($set, $run->getStartedAt()),
        ];
        if (null !== $set && SetMode::Classic === $set->getMode()) {
            $open = $this->cycles->findOpen($set);
            $metrics['cycle'] = null === $open ? null : [
                'number' => $open->getNumber(),
                'run' => $open->getRun(),
                'played' => $this->attempts->countResolved($open),
                'deadlineAt' => $open->getDeadlineAt()?->format(\DATE_ATOM),
            ];
        }

        return new Summary(
            durationMs: max(0, (int) round(((float) $closedAt->format('U.u') - (float) $run->getStartedAt()->format('U.u')) * 1000)),
            itemCount: $played,
            successCount: $stats['solved'],
            metrics: $metrics,
        );
    }

    /**
     * @throws SubjectUnavailableException
     */
    private function assertPlayable(Set $set): void
    {
        $context = ['setStatus' => $set->getStatus()->value];
        match ($set->getStatus()) {
            SetStatus::Active => null,
            SetStatus::Completed => throw new SubjectUnavailableException(CloseReason::SubjectFinished, $context, 'The set is completed.'),
            SetStatus::Paused, SetStatus::Abandoned => throw new SubjectUnavailableException(CloseReason::SubjectUnavailable, $context, sprintf('The set is %s.', $set->getStatus()->value)),
        };
        $open = $this->cycles->findOpen($set);
        if (null !== $open && CycleStatus::Resting === $open->getStatus()) {
            throw new SubjectUnavailableException(CloseReason::SubjectResting, ['availableAt' => $open->getAvailableAt()->format(\DATE_ATOM)], 'The next cycle is resting.');
        }
    }

    /**
     * After a submission: the run stops when the set is completed or its next cycle rests.
     *
     * @return array{CloseReason|null, array<string, mixed>}
     */
    private function afterSubmission(Set $set): array
    {
        if (SetStatus::Completed === $set->getStatus()) {
            return [CloseReason::SubjectFinished, ['setStatus' => $set->getStatus()->value]];
        }
        $this->entityManager->flush();
        $open = $this->cycles->findOpen($set);
        if (null !== $open && CycleStatus::Resting === $open->getStatus()) {
            return [CloseReason::SubjectResting, ['availableAt' => $open->getAvailableAt()->format(\DATE_ATOM)]];
        }

        return [null, []];
    }

    public function review(Run $run): array
    {
        return array_map(static function (Attempt $attempt): ReviewItem {
            $puzzle = PuzzleView::from($attempt->getPuzzle());

            return new ReviewItem(
                self::ITEM_TYPE,
                ReviewItem::puzzleStatus(AttemptStatus::Solved === $attempt->getStatus(), $attempt->getMistakes(), $attempt->getHintLevel(), $attempt->isSolutionShown()),
                $attempt->getDurationMs(),
                [
                    'puzzle' => [
                        'id' => $puzzle->id,
                        'fen' => $puzzle->fen,
                        'moves' => $puzzle->moves,
                        'playerColor' => $puzzle->playerColor,
                        'rating' => $puzzle->rating,
                        'themes' => $puzzle->themes,
                        'gameUrl' => $puzzle->gameUrl,
                    ],
                    // Its place in the cycle's order (1-based), as the set shows it.
                    'number' => $attempt->getOrderIndex() + 1,
                    'mistakes' => $attempt->getMistakes(),
                    'hintLevel' => $attempt->getHintLevel(),
                    'solutionShown' => $attempt->isSolutionShown(),
                ],
            );
        }, $this->attempts->findResolvedOfRun($run));
    }

    private function item(Attempt $attempt, Set $set): Item
    {
        $puzzle = PuzzleView::from($attempt->getPuzzle());
        $round = $attempt->getCycle();

        return new Item($attempt->getId()->toRfc4122(), self::ITEM_TYPE, [
            'puzzle' => [
                'id' => $puzzle->id,
                'fen' => $puzzle->fen,
                'moves' => $puzzle->moves,
                'playerColor' => $puzzle->playerColor,
                'rating' => $puzzle->rating,
                'themes' => $puzzle->themes,
                'gameUrl' => $puzzle->gameUrl,
            ],
            'mode' => $set->getMode()->value,
            'round' => $round->getNumber(),
            'cycleRun' => $round->getRun(),
            'index' => $attempt->getOrderIndex(),
            'puzzleCount' => $set->getPuzzleCount(),
            'startedAt' => $attempt->getStartedAt()->format(\DATE_ATOM),
        ]);
    }

    private function lockSet(Run $run): ?Set
    {
        return $this->sets->lockOwned($run->getSubjectId(), $run->getUser());
    }
}
