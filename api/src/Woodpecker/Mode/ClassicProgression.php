<?php

declare(strict_types=1);

namespace App\Woodpecker\Mode;

use App\Activity\EventPublisher;
use App\Entity\Woodpecker\Cycle;
use App\Entity\Woodpecker\Set;
use App\Enum\Woodpecker\CycleStatus;
use App\Enum\Woodpecker\SetMode;
use App\Repository\Woodpecker\AttemptRepository;
use App\Repository\Woodpecker\CycleRepository;
use App\Woodpecker\Cycle\CycleOrder;
use App\Woodpecker\Event\CycleCompleted;
use App\Woodpecker\Event\CycleLost;
use App\Woodpecker\Event\SetCompleted;
use App\Woodpecker\Exception\SetNotPlayableException;
use App\Woodpecker\Schedule\DeadlineCalculator;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Classic mode: cycles with shrinking deadlines (docs/WOODPECKER.md, "Deadlines" and "Playing a
 * cycle"). A run past its deadline is lost and the same cycle starts again; a completed run opens
 * the next cycle, after the rest days, until the last one completes the set.
 */
final class ClassicProgression implements ProgressionInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CycleRepository $cycles,
        private readonly AttemptRepository $attempts,
        private readonly EventPublisher $events,
    ) {
    }

    public function mode(): SetMode
    {
        return SetMode::Classic;
    }

    public function start(Set $set, \DateTimeImmutable $now): void
    {
        $this->openRun($set, 1, 1, $now, $now);
    }

    public function openRound(Set $set, \DateTimeImmutable $now): ?Cycle
    {
        return null;
    }

    /**
     * A resting run whose rest is over becomes active; an active run past its deadline is lost
     * and a new run of the same cycle starts now, with the same length.
     */
    public function refresh(Set $set, Cycle $open, \DateTimeImmutable $now): void
    {
        if (CycleStatus::Resting === $open->getStatus() && $now >= $open->getAvailableAt()) {
            $open->activate();
        }

        if (CycleStatus::Active === $open->getStatus() && $now >= $open->requireDeadlineAt()) {
            $played = $this->attempts->countResolved($open);
            $open->lose($now);
            $this->events->publish(new CycleLost(
                userId: $set->getUser()->getId()->toRfc4122(),
                setId: $set->getId()->toRfc4122(),
                cycleNumber: $open->getNumber(),
                run: $open->getRun(),
                played: $played,
                puzzleCount: $set->getPuzzleCount(),
                deadlineAt: $open->requireDeadlineAt(),
                occurredAt: $now,
            ));
            $this->openRun($set, $open->getNumber(), $open->getRun() + 1, $now, $now);
        }
    }

    public function assertPlayable(Set $set, Cycle $open): void
    {
        if (CycleStatus::Resting === $open->getStatus()) {
            throw new SetNotPlayableException(SetNotPlayableException::RESTING, $open->getAvailableAt());
        }
    }

    public function positionAt(Set $set, Cycle $round, int $index): ?int
    {
        return CycleOrder::positions($set->getPuzzleCount(), $round->getSeed(), $set->isShuffled())[$index] ?? null;
    }

    public function afterSubmission(Set $set, Cycle $round, \DateTimeImmutable $now): void
    {
        if ($this->attempts->countResolved($round) >= $set->getPuzzleCount()) {
            $this->completeRun($set, $round, $now);
        }
    }

    /**
     * The open run's dates move by the pause length (then to the end of a local day).
     */
    public function resume(Set $set, \DateTimeImmutable $pausedAt, \DateTimeImmutable $now): void
    {
        $cycle = $this->cycles->findOpen($set);
        if (null === $cycle) {
            return;
        }
        $seconds = max(0, $now->getTimestamp() - $pausedAt->getTimestamp());
        $timezone = $set->getUser()->getDateTimeZone();
        $availableAt = CycleStatus::Resting === $cycle->getStatus()
            ? DeadlineCalculator::shift($cycle->getAvailableAt(), $seconds, $timezone)
            : $cycle->getAvailableAt();
        $cycle->reschedule($availableAt, DeadlineCalculator::shift($cycle->requireDeadlineAt(), $seconds, $timezone));
    }

    private function completeRun(Set $set, Cycle $cycle, \DateTimeImmutable $now): void
    {
        $cycle->complete($now);
        $stats = $this->attempts->statsFor([$cycle])[$cycle->getId()->toRfc4122()] ?? null;
        $config = $set->getConfig();
        $this->events->publish(new CycleCompleted(
            userId: $set->getUser()->getId()->toRfc4122(),
            setId: $set->getId()->toRfc4122(),
            cycleNumber: $cycle->getNumber(),
            run: $cycle->getRun(),
            cycleCount: $config->cycleCount,
            puzzleCount: $set->getPuzzleCount(),
            solved: $stats->solved ?? 0,
            failed: $stats->failed ?? 0,
            activeMs: $stats->activeMs ?? 0,
            calendarMs: (int) round(((float) $now->format('U.u') - (float) $cycle->getAvailableAt()->format('U.u')) * 1000),
            deadlineAt: $cycle->requireDeadlineAt(),
            occurredAt: $now,
        ));

        if ($cycle->getNumber() >= $config->cycleCount) {
            $set->complete($now);
            $lostRuns = \count(array_filter($this->cycles->findBySet($set), static fn (Cycle $c): bool => CycleStatus::Lost === $c->getStatus()));
            $this->events->publish(new SetCompleted(
                userId: $set->getUser()->getId()->toRfc4122(),
                setId: $set->getId()->toRfc4122(),
                cycleCount: $config->cycleCount,
                puzzleCount: $set->getPuzzleCount(),
                lostRuns: $lostRuns,
                occurredAt: $now,
            ));

            return;
        }

        $timezone = $set->getUser()->getDateTimeZone();
        $this->openRun($set, $cycle->getNumber() + 1, 1, DeadlineCalculator::restEnd($now, $config->restDays, $timezone), $now);
    }

    /**
     * Starts a run: its deadline is counted from when it becomes available.
     */
    private function openRun(Set $set, int $number, int $run, \DateTimeImmutable $availableAt, \DateTimeImmutable $now): void
    {
        $days = DeadlineCalculator::cycleDays($set->getConfig(), $number);
        $this->entityManager->persist(new Cycle(
            $set,
            $number,
            $run,
            $days,
            CycleOrder::newSeed(),
            $availableAt,
            DeadlineCalculator::deadline($availableAt, $days, $set->getUser()->getDateTimeZone()),
            $now,
        ));
    }
}
