<?php

declare(strict_types=1);

namespace App\ApiResource\Training;

use App\Entity\Training\Run;

/**
 * A closed run in a subject's history (e.g. the runs of a Woodpecker set).
 */
final class RunRecord
{
    public string $id;
    public int $budgetSeconds;
    public \DateTimeImmutable $startedAt;
    public ?\DateTimeImmutable $closedAt;
    public ?string $closeReason;
    /** @var array<string, mixed>|null */
    public ?array $summary;

    public static function from(Run $run): self
    {
        $view = new self();
        $view->id = $run->getId()->toRfc4122();
        $view->budgetSeconds = $run->getBudgetSeconds();
        $view->startedAt = $run->getStartedAt();
        $view->closedAt = $run->getClosedAt();
        $view->closeReason = $run->getCloseReason()?->value;
        $view->summary = $run->getSummary();

        return $view;
    }
}
