<?php

declare(strict_types=1);

namespace App\ApiResource\Training;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation;
use App\ApiResource\Woodpecker\Set;
use App\Entity\Training\Run as RunEntity;
use App\State\Training\RunProvider;
use App\State\Training\StartRunProcessor;
use App\State\Training\StopRunProcessor;

/**
 * A timed run of the current user (docs/TRAINING.md). `serverNow` lets the client measure its
 * clock offset; the server alone decides when time is up. Another user's run answers 404.
 */
#[ApiResource(
    shortName: 'TrainingRun',
    normalizationContext: ['skip_null_values' => false],
    operations: [
        new Post(uriTemplate: '/training/runs', input: StartRunInput::class, processor: StartRunProcessor::class),
        new Get(
            uriTemplate: '/training/runs/current',
            openapi: new Operation(summary: 'The active run of the user (404 when none).'),
            provider: RunProvider::class,
            name: 'training_run_current',
        ),
        new Get(uriTemplate: '/training/runs/{id}', requirements: ['id' => Set::UUID_PATTERN], provider: RunProvider::class),
        new Post(
            uriTemplate: '/training/runs/{id}/stop',
            requirements: ['id' => Set::UUID_PATTERN],
            status: 200,
            openapi: new Operation(summary: 'Ends the run now (idempotent).'),
            input: false,
            read: false,
            processor: StopRunProcessor::class,
            name: 'training_run_stop',
        ),
    ],
)]
final class Run
{
    #[ApiProperty(identifier: true)]
    public string $id;
    public string $module;
    public string $subjectType;
    public string $subjectId;
    /** active or closed */
    public string $status;
    /** time_up, stopped, subject_finished, subject_resting or subject_unavailable */
    public ?string $closeReason;
    public int $budgetSeconds;
    public \DateTimeImmutable $startedAt;
    public \DateTimeImmutable $expiresAt;
    public ?\DateTimeImmutable $closedAt;
    /** The server's clock when answering. */
    public \DateTimeImmutable $serverNow;
    /** @var array<string, mixed>|null the normalized recap, once closed */
    public ?array $summary;
    public ?string $parentId;

    public static function from(RunEntity $run, \DateTimeImmutable $now): self
    {
        $view = new self();
        $view->id = $run->getId()->toRfc4122();
        $view->module = $run->getModule()->value;
        $view->subjectType = $run->getSubjectType();
        $view->subjectId = $run->getSubjectId()->toRfc4122();
        $view->status = $run->getStatus()->value;
        $view->closeReason = $run->getCloseReason()?->value;
        $view->budgetSeconds = $run->getBudgetSeconds();
        $view->startedAt = $run->getStartedAt();
        $view->expiresAt = $run->getExpiresAt();
        $view->closedAt = $run->getClosedAt();
        $view->serverNow = $now;
        $view->summary = $run->getSummary();
        $view->parentId = $run->getParentId()?->toRfc4122();

        return $view;
    }
}
