<?php

declare(strict_types=1);

namespace App\ApiResource\Training;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use ApiPlatform\OpenApi\Model\Operation;
use App\ApiResource\Woodpecker\Set;
use App\Entity\Training\Plan as PlanEntity;
use App\State\Training\PlanProcessor;
use App\State\Training\PlanProvider;

/**
 * A saved training session of the current user (docs/TRAINING.md): program, repetition, public
 * flag, reminder and calendar settings, next occurrence. Another user's plan answers 404.
 */
#[ApiResource(
    shortName: 'TrainingPlan',
    normalizationContext: ['skip_null_values' => false],
    operations: [
        new GetCollection(
            uriTemplate: '/training/plans',
            paginationEnabled: false,
            openapi: new Operation(summary: 'The user\'s saved sessions, most recently changed first.'),
            provider: PlanProvider::class,
        ),
        new Post(uriTemplate: '/training/plans', input: PlanInput::class, processor: PlanProcessor::class),
        new Get(uriTemplate: '/training/plans/{id}', requirements: ['id' => Set::UUID_PATTERN], provider: PlanProvider::class),
        new Put(uriTemplate: '/training/plans/{id}', requirements: ['id' => Set::UUID_PATTERN], input: PlanInput::class, read: false, processor: PlanProcessor::class),
        new Delete(uriTemplate: '/training/plans/{id}', requirements: ['id' => Set::UUID_PATTERN], read: false, processor: PlanProcessor::class),
    ],
)]
final class Plan
{
    #[ApiProperty(identifier: true)]
    public string $id;
    public string $title;
    public string $description;
    /** @var list<array{module: string, minutes: int, notes: string, settings: array<string, mixed>}> */
    public array $steps;
    public int $totalMinutes;
    /** on_demand, daily or weekly */
    public string $repetition;
    public ?string $time;
    /** @var list<int> */
    public array $weekdays;
    public bool $public;
    public bool $reminderEnabled;
    /** @var list<string> */
    public array $reminderChannels;
    public int $reminderMinutes;
    public bool $calendarEnabled;
    /** The next occurrence (UTC), null on demand. */
    public ?\DateTimeImmutable $nextAt;
    public \DateTimeImmutable $createdAt;
    public \DateTimeImmutable $updatedAt;

    public static function from(PlanEntity $plan, \DateTimeImmutable $now): self
    {
        $view = new self();
        $view->id = $plan->getId()->toRfc4122();
        $view->title = $plan->getTitle();
        $view->description = $plan->getDescription();
        $view->steps = $plan->getSteps();
        $view->totalMinutes = array_sum(array_column($plan->getSteps(), 'minutes'));
        $view->repetition = $plan->getRepetition()->value;
        $view->time = $plan->getTime();
        $view->weekdays = $plan->getWeekdays();
        $view->public = $plan->isPublic();
        $view->reminderEnabled = $plan->isReminderEnabled();
        $view->reminderChannels = $plan->getReminderChannels();
        $view->reminderMinutes = $plan->getReminderMinutes();
        $view->calendarEnabled = $plan->isCalendarEnabled();
        $view->nextAt = $plan->schedule()->nextAfter($now);
        $view->createdAt = $plan->getCreatedAt();
        $view->updatedAt = $plan->getUpdatedAt();

        return $view;
    }
}
