<?php

declare(strict_types=1);

namespace App\ApiResource\Training;

use App\Entity\Training\Run;

/**
 * A step of a session as the SPA sees it, with the recap of its run once played.
 *
 * @phpstan-import-type StepData from \App\Entity\Training\Session
 */
final class SessionStepView
{
    public int $index;
    public string $module;
    public int $minutes;
    public string $notes;
    /** @var array<string, mixed> */
    public array $settings;
    /** pending, running, done, skipped or unplayed */
    public string $status;
    public ?string $runId;
    /** @var array{reason: string, message: string}|null why it could not start, until retried or skipped */
    public ?array $blocked;
    /** @var array<string, mixed>|null the run's normalized recap, once closed */
    public ?array $summary;

    /**
     * @param StepData $step
     */
    public static function from(int $index, array $step, ?Run $run): self
    {
        $view = new self();
        $view->index = $index;
        $view->module = $step['module'];
        $view->minutes = $step['minutes'];
        $view->notes = $step['notes'];
        $view->settings = $step['settings'];
        $view->status = $step['status'];
        $view->runId = $step['runId'];
        $view->blocked = $step['blocked'];
        $view->summary = $run?->getSummary();

        return $view;
    }
}
