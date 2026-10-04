<?php

declare(strict_types=1);

namespace App\ApiResource\Training;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation;
use App\ApiResource\Woodpecker\Set;
use App\Entity\Training\Run as RunEntity;
use App\Entity\Training\Session as SessionEntity;
use App\State\Training\CreateSessionProcessor;
use App\State\Training\SessionActionProcessor;
use App\State\Training\SessionProvider;

/**
 * A training session of the current user (docs/TRAINING.md): its frozen program, where it stands
 * and the recap of each step played. Another user's session answers 404.
 */
#[ApiResource(
    shortName: 'TrainingSession',
    normalizationContext: ['skip_null_values' => false],
    operations: [
        new Post(
            uriTemplate: '/training/sessions',
            openapi: new Operation(summary: 'Launches a session (its first step is started by /next).'),
            input: CreateSessionInput::class,
            processor: CreateSessionProcessor::class,
        ),
        new GetCollection(
            uriTemplate: '/training/sessions',
            paginationEnabled: false,
            openapi: new Operation(summary: 'The user\'s latest sessions, newest first.'),
            provider: SessionProvider::class,
        ),
        new Get(
            uriTemplate: '/training/sessions/current',
            openapi: new Operation(summary: 'The active session of the user (404 when none).'),
            provider: SessionProvider::class,
            name: 'training_session_current',
        ),
        new Get(uriTemplate: '/training/sessions/{id}', requirements: ['id' => Set::UUID_PATTERN], provider: SessionProvider::class),
        new Post(
            uriTemplate: '/training/plans/{id}/launch',
            requirements: ['id' => Set::UUID_PATTERN],
            status: 201,
            openapi: new Operation(summary: 'Launches a session from a saved one (its first step is started by /training/sessions/{id}/next).'),
            input: false,
            read: false,
            processor: CreateSessionProcessor::class,
            name: 'training_plan_launch',
        ),
        new Post(
            uriTemplate: '/training/sessions/{id}/skip',
            requirements: ['id' => Set::UUID_PATTERN],
            status: 200,
            openapi: new Operation(summary: 'Passes the current step (not while its run is in progress).'),
            input: false,
            read: false,
            processor: SessionActionProcessor::class,
            name: 'training_session_skip',
        ),
        new Post(
            uriTemplate: '/training/sessions/{id}/abandon',
            requirements: ['id' => Set::UUID_PATTERN],
            status: 200,
            openapi: new Operation(summary: 'Ends the session now (a run of it in progress is stopped).'),
            input: false,
            read: false,
            processor: SessionActionProcessor::class,
            name: 'training_session_abandon',
        ),
    ],
)]
final class Session
{
    /** The latest sessions listed. */
    public const RECENT_LIMIT = 10;

    #[ApiProperty(identifier: true)]
    public string $id;
    public string $title;
    public string $description;
    /** active, completed, abandoned or expired */
    public string $status;
    public int $currentIndex;
    public \DateTimeImmutable $startedAt;
    /** The end of its local day: an unfinished session closes then. */
    public \DateTimeImmutable $expiresAt;
    public ?\DateTimeImmutable $closedAt;
    /** Time played in its runs. */
    public int $durationMs;
    /** The saved session it was launched from, if any. */
    public ?string $planId;
    /** @var list<SessionStepView> */
    #[ApiProperty(genId: false)]
    public array $steps;

    /**
     * @param list<RunEntity> $runs the session's runs
     */
    public static function from(SessionEntity $session, array $runs): self
    {
        $byId = [];
        foreach ($runs as $run) {
            $byId[$run->getId()->toRfc4122()] = $run;
        }
        $view = new self();
        $view->id = $session->getId()->toRfc4122();
        $view->title = $session->getTitle();
        $view->description = $session->getDescription();
        $view->status = $session->getStatus()->value;
        $view->currentIndex = $session->getCurrentIndex();
        $view->startedAt = $session->getStartedAt();
        $view->expiresAt = $session->getExpiresAt();
        $view->closedAt = $session->getClosedAt();
        $view->planId = $session->getPlan()?->getId()->toRfc4122();
        $view->steps = [];
        $view->durationMs = 0;
        foreach ($session->getSteps() as $i => $step) {
            $run = null === $step['runId'] ? null : ($byId[$step['runId']] ?? null);
            $view->steps[] = $stepView = SessionStepView::from($i, $step, $run);
            $view->durationMs += \is_int($stepView->summary['durationMs'] ?? null) ? $stepView->summary['durationMs'] : 0;
        }

        return $view;
    }
}
