<?php

declare(strict_types=1);

namespace App\Training\Run;

use App\Activity\EventPublisher;
use App\Entity\Training\Run;
use App\Entity\User;
use App\Enum\Training\CloseReason;
use App\Enum\Training\Module;
use App\Repository\Training\RunRepository;
use App\Training\Event\RunCompleted;
use App\Training\Exception\RunClosedException;
use App\Training\Exception\RunInProgressException;
use App\Training\Exception\RunNotFoundException;
use App\Training\Exception\SubjectUnavailableException;
use App\Training\Exception\SubmissionTooLateException;
use App\Training\Module\ItemSubmission;
use App\Training\Module\ModuleRegistry;
use App\Training\Module\TimeboxedModuleInterface;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Runs timed runs of any module (docs/TRAINING.md). Time is counted here only, on the server
 * clock:
 *
 * - no item is served from the expiry on; the run then closes (time_up), at its expiry;
 * - a submission is accepted if it arrives at most {@see self::SUBMISSION_TOLERANCE_MS} after the
 *   expiry (network latency, not a grace period: nothing can be served meanwhile), else rejected;
 * - a run left behind (tab closed) is closed lazily, at the user's next training request, with
 *   its expiry as closing instant;
 * - one active run per user (database), a second start is refused.
 *
 * Every operation locks the run first, then the module locks its subject.
 */
final class TimeboxRunner
{
    public const SUBMISSION_TOLERANCE_MS = 2_000;
    public const MIN_BUDGET_SECONDS = 60;
    public const MAX_BUDGET_SECONDS = 3_600;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly RunRepository $runs,
        private readonly ModuleRegistry $modules,
        private readonly EventPublisher $events,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @param array<string, mixed> $config module-specific options
     *
     * @throws RunInProgressException
     * @throws \App\Training\Exception\SubjectNotFoundException
     * @throws SubjectUnavailableException
     */
    public function start(User $user, Module $module, Uuid $subjectId, int $budgetSeconds, array $config = []): Run
    {
        if ($budgetSeconds < self::MIN_BUDGET_SECONDS || $budgetSeconds > self::MAX_BUDGET_SECONDS) {
            throw new \InvalidArgumentException('Budget out of range.');
        }
        $this->closeExpired($user);
        $implementation = $this->modules->for($module);

        try {
            return $this->entityManager->wrapInTransaction(function () use ($user, $module, $implementation, $subjectId, $budgetSeconds, $config): Run {
                $now = $this->now();
                if (null !== $this->runs->findActiveOf($user)) {
                    throw new RunInProgressException();
                }
                $run = new Run($user, $module, $implementation->subjectType(), $subjectId, $budgetSeconds, $config, $now);
                $this->entityManager->persist($run);
                // The unique index on active_user_id rejects a concurrent second start here.
                $this->entityManager->flush();
                $implementation->start($run, $now);
                $this->entityManager->flush();

                return $run;
            });
        } catch (UniqueConstraintViolationException) {
            throw new RunInProgressException();
        }
    }

    /**
     * The active run, if any (a run past its time is closed first).
     */
    public function current(User $user): ?Run
    {
        $this->closeExpired($user);

        return $this->runs->findActiveOf($user);
    }

    /**
     * @throws RunNotFoundException
     */
    public function get(User $user, Uuid $runId): Run
    {
        $this->closeExpired($user);

        return $this->runs->findOwned($runId, $user) ?? throw new RunNotFoundException();
    }

    /**
     * The item to play, or none when the run is (now) closed: time up, or the subject cannot be
     * played any more.
     *
     * @throws RunNotFoundException
     */
    public function next(User $user, Uuid $runId): Step
    {
        return $this->entityManager->wrapInTransaction(function () use ($user, $runId): Step {
            $now = $this->now();
            $run = $this->runs->lockOwned($runId, $user) ?? throw new RunNotFoundException();
            if (!$run->isActive()) {
                return new Step($run);
            }
            if ($run->isExpired($now)) {
                $this->close($run, CloseReason::TimeUp, $run->getExpiresAt(), $now);

                return new Step($run);
            }
            try {
                return new Step($run, $this->module($run)->next($run, $now));
            } catch (SubjectUnavailableException $e) {
                // Keep what the module applied (e.g. a lost cycle), close the run with it.
                $this->close($run, $e->reason, $now, $now, $e->context);

                return new Step($run);
            }
        });
    }

    /**
     * Resolves an item of the run; closes the run when its time is up or its subject is done.
     *
     * @throws RunNotFoundException
     * @throws RunClosedException          the run was already closed, or closes because its subject cannot be played
     * @throws SubmissionTooLateException  (the run is closed)
     * @throws \App\Training\Exception\ItemNotFoundException
     * @throws \App\Training\Exception\ItemAlreadySubmittedException
     * @throws \App\Training\Exception\ItemClosedException
     * @throws \App\Training\Exception\InvalidItemSubmissionException
     */
    public function submit(User $user, Uuid $runId, ItemSubmission $submission): Step
    {
        $outcome = $this->entityManager->wrapInTransaction(function () use ($user, $runId, $submission): Step|string {
            $now = $this->now();
            $run = $this->runs->lockOwned($runId, $user) ?? throw new RunNotFoundException();
            if (!$run->isActive()) {
                return RunClosedException::class;
            }
            if ($now > $run->getExpiresAt()->modify(sprintf('+%d milliseconds', self::SUBMISSION_TOLERANCE_MS))) {
                $this->close($run, CloseReason::TimeUp, $run->getExpiresAt(), $now);

                return SubmissionTooLateException::class;
            }
            try {
                $result = $this->module($run)->submit($run, $submission, $now);
            } catch (SubjectUnavailableException $e) {
                $this->close($run, $e->reason, $now, $now, $e->context);

                return RunClosedException::class;
            }
            if (null !== $result->closes) {
                $this->close($run, $result->closes, $now, $now, $result->context);
            } elseif ($run->isExpired($now)) {
                $this->close($run, CloseReason::TimeUp, $run->getExpiresAt(), $now);
            }

            return new Step($run, null, $result);
        });

        return match ($outcome) {
            RunClosedException::class => throw new RunClosedException(),
            SubmissionTooLateException::class => throw new SubmissionTooLateException(),
            default => $outcome,
        };
    }

    /**
     * Ends the run early (or as time_up if its time is already over). Idempotent.
     *
     * @throws RunNotFoundException
     */
    public function stop(User $user, Uuid $runId): Run
    {
        return $this->entityManager->wrapInTransaction(function () use ($user, $runId): Run {
            $now = $this->now();
            $run = $this->runs->lockOwned($runId, $user) ?? throw new RunNotFoundException();
            if ($run->isActive()) {
                $run->isExpired($now)
                    ? $this->close($run, CloseReason::TimeUp, $run->getExpiresAt(), $now)
                    : $this->close($run, CloseReason::Stopped, $now, $now);
            }

            return $run;
        });
    }

    /**
     * Lazy closing: the user's active run whose time (and submission tolerance) is over closes at
     * its expiry. Called before every training request of the user, and before the untimed play of
     * a subject that a run could be holding.
     */
    public function closeExpired(User $user): void
    {
        $this->entityManager->wrapInTransaction(function () use ($user): void {
            $now = $this->now();
            $run = $this->runs->lockActiveOf($user);
            if (null !== $run && $now > $run->getExpiresAt()->modify(sprintf('+%d milliseconds', self::SUBMISSION_TOLERANCE_MS))) {
                $this->close($run, CloseReason::TimeUp, $run->getExpiresAt(), $now);
            }
        });
    }

    /**
     * @param array<string, mixed> $context
     */
    private function close(Run $run, CloseReason $reason, \DateTimeImmutable $closedAt, \DateTimeImmutable $now, array $context = []): void
    {
        $module = $this->module($run);
        $module->close($run, $reason, $now);
        $this->entityManager->flush();
        $summary = $module->summarize($run, $closedAt);
        $run->close($reason, $closedAt, $summary->toArray($context));
        $this->events->publish(new RunCompleted(
            userId: $run->getUser()->getId()->toRfc4122(),
            runId: $run->getId()->toRfc4122(),
            module: $run->getModule()->value,
            subjectType: $run->getSubjectType(),
            subjectId: $run->getSubjectId()->toRfc4122(),
            parentId: $run->getParentId()?->toRfc4122(),
            reason: $reason->value,
            budgetSeconds: $run->getBudgetSeconds(),
            durationMs: $summary->durationMs,
            itemCount: $summary->itemCount,
            successCount: $summary->successCount,
            startedAt: $run->getStartedAt(),
            occurredAt: $closedAt,
        ));
        $this->entityManager->flush();
    }

    private function module(Run $run): TimeboxedModuleInterface
    {
        return $this->modules->for($run->getModule());
    }

    private function now(): \DateTimeImmutable
    {
        return $this->clock->now()->setTimezone(new \DateTimeZone('UTC'));
    }
}
