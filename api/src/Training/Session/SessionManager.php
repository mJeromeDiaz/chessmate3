<?php

declare(strict_types=1);

namespace App\Training\Session;

use App\Activity\EventPublisher;
use App\Entity\Training\Plan;
use App\Entity\Training\Run;
use App\Entity\Training\Session;
use App\Entity\User;
use App\Enum\Training\Module;
use App\Enum\Training\SessionStatus;
use App\Enum\Training\StepStatus;
use App\Repository\Training\RunRepository;
use App\Repository\Training\SessionRepository;
use App\Training\Event\SessionClosed;
use App\Training\Exception\InvalidRunConfigException;
use App\Training\Exception\InvalidSessionException;
use App\Training\Exception\RunInProgressException;
use App\Training\Exception\SessionClosedException;
use App\Training\Exception\SessionInProgressException;
use App\Training\Exception\SessionNotFoundException;
use App\Training\Exception\StepBlockedException;
use App\Training\Exception\StepRunningException;
use App\Training\Exception\SubjectNotFoundException;
use App\Training\Exception\SubjectUnavailableException;
use App\Training\Run\TimeboxRunner;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Training sessions (docs/TRAINING.md): a program of modules frozen at launch, played step by step,
 * each step a timed run of {@see TimeboxRunner} whose parent is the session. Rules validated on
 * 2026-10-04: no auto-start (the user asks for the next step after each recap), a step that cannot
 * start stays current with its reason (try again, skip or abandon), and a session belongs to its
 * local day: past it, it closes lazily (expired) once no run of it is active.
 *
 * The session's state follows its runs lazily: every request first closes the user's expired run,
 * then reconciles the session (a closed run finishes its step). Lock order: session, then run;
 * the runner's own transactions are never nested in a session transaction (a refusal there would
 * close the entity manager).
 */
final class SessionManager
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SessionRepository $sessions,
        private readonly RunRepository $runs,
        private readonly StepChecker $checker,
        private readonly TimeboxRunner $runner,
        private readonly EventPublisher $events,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * Launches a session: every step is checked by its module first.
     *
     * @param list<array{module: Module, minutes: int, notes: string, settings: array<string, mixed>}> $steps
     *
     * @throws InvalidSessionException    a step is invalid (its number in the message)
     * @throws SessionInProgressException
     */
    public function create(User $user, string $title, string $description, array $steps, ?Plan $plan = null): Session
    {
        $this->checker->check($user, $steps, true);
        $this->closeStale($user);

        $now = $this->now();
        $timezone = $user->getDateTimeZone();
        $endOfDay = $now->setTimezone($timezone)->modify('tomorrow')->setTimezone(new \DateTimeZone('UTC'));

        try {
            $created = $this->entityManager->wrapInTransaction(function () use ($user, $title, $description, $steps, $now, $endOfDay, $plan): ?Session {
                if (null !== $this->sessions->findActiveOf($user)) {
                    return null;
                }
                $session = new Session($user, $title, $description, $steps, $now, $endOfDay, $plan);
                $this->entityManager->persist($session);
                // The unique index on active_user_id rejects a concurrent second launch here.
                $this->entityManager->flush();

                return $session;
            });
        } catch (UniqueConstraintViolationException) {
            $created = null;
        }

        return $created ?? throw new SessionInProgressException();
    }

    /**
     * The active session, reconciled with its runs, if any.
     */
    public function current(User $user): ?Session
    {
        $this->closeStale($user);

        return $this->sessions->findActiveOf($user);
    }

    /**
     * @throws SessionNotFoundException
     */
    public function get(User $user, Uuid $id): Session
    {
        return $this->sync($user, $id);
    }

    /**
     * @return list<Session> newest first, reconciled
     */
    public function recent(User $user, int $limit): array
    {
        $this->closeStale($user);

        return $this->sessions->findRecentOf($user, $limit);
    }

    /**
     * Starts the current step (or hands back its run when it is already being played).
     *
     * @return array{Session, Run}
     *
     * @throws SessionNotFoundException
     * @throws SessionClosedException
     * @throws StepBlockedException    the step cannot start now; it stays current, with the reason
     * @throws RunInProgressException  another run (not of this session) is in progress
     */
    public function next(User $user, Uuid $id): array
    {
        $session = $this->sync($user, $id);
        if (!$session->isActive()) {
            throw new SessionClosedException();
        }
        $runId = $session->currentRunId();
        if (StepStatus::Running === $session->currentStatus() && null !== $runId) {
            $run = $this->runs->findOwned($runId, $user) ?? throw new \LogicException('A running step has its run.');

            return [$session, $run];
        }
        $step = $session->currentStep() ?? throw new SessionClosedException();
        $module = Module::from($step['module']);

        try {
            $prepared = $this->checker->prepare($user, $module, $step['settings'], $step['notes']);
            $run = $this->runner->start($user, $module, $prepared->subjectId, $step['minutes'] * 60, $prepared->config, $session->getId());
        } catch (SubjectUnavailableException $e) {
            $reason = \is_string($e->context['reason'] ?? null) ? $e->context['reason'] : $e->reason->value;
            $this->block($user, $id, $reason, $e->getMessage());
        } catch (SubjectNotFoundException) {
            $this->block($user, $id, 'subject_not_found', 'The subject of this step no longer exists.');
        } catch (InvalidRunConfigException $e) {
            $this->block($user, $id, 'invalid_settings', $e->getMessage());
        }

        $linked = $this->entityManager->wrapInTransaction(function () use ($user, $id, $run): ?Session {
            $session = $this->sessions->lockOwned($id, $user);
            if (null === $session || !$session->isActive() || StepStatus::Pending !== $session->currentStatus()) {
                return null;
            }
            $session->startCurrent($run->getId());

            return $session;
        });
        if (null === $linked) {
            // The session ended (or moved on) meanwhile: the run must not outlive it.
            $this->runner->stop($user, $run->getId());

            throw new SessionClosedException();
        }

        return [$linked, $run];
    }

    /**
     * Passes the current step (not while its run is in progress).
     *
     * @throws SessionNotFoundException
     * @throws SessionClosedException
     * @throws StepRunningException
     */
    public function skip(User $user, Uuid $id): Session
    {
        $this->runner->closeExpired($user);
        $outcome = $this->entityManager->wrapInTransaction(function () use ($user, $id): Session|string {
            $session = $this->sessions->lockOwned($id, $user) ?? throw new SessionNotFoundException();
            $this->reconcile($session);
            if (!$session->isActive()) {
                return SessionClosedException::class;
            }
            if (StepStatus::Running === $session->currentStatus()) {
                return StepRunningException::class;
            }
            $session->skipCurrent();
            $this->reconcile($session);

            return $session;
        });

        return match ($outcome) {
            SessionClosedException::class => throw new SessionClosedException(),
            StepRunningException::class => throw new StepRunningException(),
            default => $outcome,
        };
    }

    /**
     * Ends the session now: a run of it in progress is stopped first (and counts as played).
     *
     * @throws SessionNotFoundException
     */
    public function abandon(User $user, Uuid $id): Session
    {
        $session = $this->sync($user, $id);
        $runId = $session->currentRunId();
        if ($session->isActive() && StepStatus::Running === $session->currentStatus() && null !== $runId) {
            $this->runner->stop($user, $runId);
        }

        return $this->entityManager->wrapInTransaction(function () use ($user, $id): Session {
            $session = $this->sessions->lockOwned($id, $user) ?? throw new SessionNotFoundException();
            $this->reconcile($session);
            if ($session->isActive()) {
                $this->closeSession($session, SessionStatus::Abandoned);
            }

            return $session;
        });
    }

    /**
     * Lazy closing: the user's active session is reconciled with its runs (and closed when over
     * or past its day).
     */
    private function closeStale(User $user): void
    {
        $this->runner->closeExpired($user);
        $active = $this->sessions->findActiveOf($user);
        if (null !== $active) {
            $this->sync($user, $active->getId());
        }
    }

    /**
     * @throws SessionNotFoundException
     */
    private function sync(User $user, Uuid $id): Session
    {
        $this->runner->closeExpired($user);

        return $this->entityManager->wrapInTransaction(function () use ($user, $id): ?Session {
            $session = $this->sessions->lockOwned($id, $user);
            if (null !== $session) {
                $this->reconcile($session);
            }

            return $session;
        }) ?? throw new SessionNotFoundException();
    }

    /**
     * Records why the current step cannot start, then refuses.
     *
     * @throws StepBlockedException
     */
    private function block(User $user, Uuid $id, string $reason, string $message): never
    {
        $this->entityManager->wrapInTransaction(function () use ($user, $id, $reason, $message): void {
            $session = $this->sessions->lockOwned($id, $user);
            if (null !== $session && $session->isActive() && StepStatus::Pending === $session->currentStatus()) {
                $session->blockCurrent($reason, $message);
            }
        });

        throw new StepBlockedException($reason, $message);
    }

    /**
     * Brings a locked session up to date with its runs: a closed run finishes its step; a session
     * with no step left completes; one past its day, with no run in progress, expires.
     */
    private function reconcile(Session $session): void
    {
        if (!$session->isActive()) {
            return;
        }
        $runId = $session->currentRunId();
        if (StepStatus::Running === $session->currentStatus() && null !== $runId) {
            $run = $this->runs->find($runId);
            if (null === $run || !$run->isActive()) {
                $session->finishCurrent();
            }
        }
        if ($session->isOver()) {
            $this->closeSession($session, SessionStatus::Completed);
        } elseif (StepStatus::Running !== $session->currentStatus() && $this->now() >= $session->getExpiresAt()) {
            $this->closeSession($session, SessionStatus::Expired);
        }
    }

    private function closeSession(Session $session, SessionStatus $status): void
    {
        $now = $this->now();
        $session->close($status, $now);
        $durationMs = 0;
        foreach ($this->runs->findByParent($session->getId()) as $run) {
            $summary = $run->getSummary();
            $durationMs += \is_int($summary['durationMs'] ?? null) ? $summary['durationMs'] : 0;
        }
        $counts = array_count_values(array_column($session->getSteps(), 'status'));
        // Same transaction: the event exists if and only if the session is closed.
        $this->events->publish(new SessionClosed(
            userId: $session->getUser()->getId()->toRfc4122(),
            sessionId: $session->getId()->toRfc4122(),
            status: $status->value,
            stepCount: \count($session->getSteps()),
            doneCount: $counts[StepStatus::Done->value] ?? 0,
            skippedCount: $counts[StepStatus::Skipped->value] ?? 0,
            durationMs: $durationMs,
            startedAt: $session->getStartedAt(),
            occurredAt: $now,
        ));
    }

    private function now(): \DateTimeImmutable
    {
        return $this->clock->now()->setTimezone(new \DateTimeZone('UTC'));
    }
}
