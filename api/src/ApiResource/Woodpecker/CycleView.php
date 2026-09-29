<?php

declare(strict_types=1);

namespace App\ApiResource\Woodpecker;

use App\Entity\Woodpecker\Cycle;
use App\Enum\Woodpecker\CycleStatus;
use App\Woodpecker\Schedule\DeadlineCalculator;
use App\Woodpecker\Stats\CycleStats;

/**
 * One cycle run in a set's report.
 */
final class CycleView
{
    public int $number;
    public int $run;
    /** resting, active, completed or lost */
    public string $status;
    public int $durationDays;
    public \DateTimeImmutable $availableAt;
    public \DateTimeImmutable $deadlineAt;
    public ?\DateTimeImmutable $completedAt;
    public ?\DateTimeImmutable $lostAt;
    public int $played;
    public int $solved;
    public int $failed;
    public ?float $accuracy;
    public int $activeMs;
    public ?int $averageMs;
    /** From availableAt to completion (or loss), null while open. */
    public ?int $calendarMs;
    /** Completed before the deadline (a lost run is not; an open one is null). */
    public ?bool $onTime;
    /** Local days left before the deadline, today included (open runs only). */
    public ?int $daysLeft;

    public static function from(Cycle $cycle, ?CycleStats $stats, \DateTimeImmutable $now, \DateTimeZone $timezone): self
    {
        $stats ??= new CycleStats(0, 0, 0);
        $view = new self();
        $view->number = $cycle->getNumber();
        $view->run = $cycle->getRun();
        $view->status = $cycle->getStatus()->value;
        $view->durationDays = $cycle->getDurationDays();
        $view->availableAt = $cycle->getAvailableAt();
        $view->deadlineAt = $cycle->getDeadlineAt();
        $view->completedAt = $cycle->getCompletedAt();
        $view->lostAt = $cycle->getLostAt();
        $view->played = $stats->played();
        $view->solved = $stats->solved;
        $view->failed = $stats->failed;
        $view->accuracy = null === $stats->accuracy() ? null : round($stats->accuracy(), 4);
        $view->activeMs = $stats->activeMs;
        $view->averageMs = $stats->averageMs();
        $end = $cycle->getCompletedAt() ?? $cycle->getLostAt();
        $view->calendarMs = null === $end ? null : max(0, ($end->getTimestamp() - $cycle->getAvailableAt()->getTimestamp()) * 1000);
        $view->onTime = match ($cycle->getStatus()) {
            CycleStatus::Completed => true,
            CycleStatus::Lost => false,
            default => null,
        };
        $view->daysLeft = $cycle->isOpen() ? DeadlineCalculator::daysLeft($now, $cycle->getDeadlineAt(), $timezone) : null;

        return $view;
    }
}
