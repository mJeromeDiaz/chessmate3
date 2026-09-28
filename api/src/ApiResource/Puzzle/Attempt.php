<?php

declare(strict_types=1);

namespace App\ApiResource\Puzzle;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\Parameter;
use App\Entity\Puzzle\Attempt as AttemptEntity;
use App\State\Puzzle\AttemptHistoryProvider;
use App\State\Puzzle\AttemptProvider;
use App\State\Puzzle\StartAttemptProcessor;
use App\State\Puzzle\SubmitAttemptProcessor;

/**
 * A puzzle attempt of the current user. Only the owner ever sees or submits one: the providers and
 * the submission query all filter on the authenticated user, and a foreign id answers 404.
 */
#[ApiResource(
    shortName: 'PuzzleAttempt',
    // Every field is always present (null included): a stable contract for the SPA.
    normalizationContext: ['skip_null_values' => false],
    operations: [
        new GetCollection(
            uriTemplate: '/puzzles/attempts',
            priority: 10,
            paginationItemsPerPage: 20,
            paginationMaximumItemsPerPage: 50,
            paginationClientItemsPerPage: true,
            openapi: new Operation(
                summary: 'The current user\'s resolved attempts, newest first.',
                parameters: [
                    new Parameter('result', 'query', 'solved or failed', schema: ['type' => 'string', 'enum' => ['solved', 'failed']]),
                    new Parameter('theme', 'query', 'A theme key, e.g. fork', schema: ['type' => 'string']),
                ],
            ),
            provider: AttemptHistoryProvider::class,
        ),
        new Get(
            uriTemplate: '/puzzles/attempts/{id}',
            requirements: ['id' => self::UUID_PATTERN],
            priority: 10,
            provider: AttemptProvider::class,
        ),
        new Post(
            uriTemplate: '/puzzles/attempts',
            priority: 10,
            openapi: new Operation(summary: 'Hands out the next puzzle (or the pending one), or starts an unrated replay.'),
            input: StartAttemptInput::class,
            processor: StartAttemptProcessor::class,
        ),
        new Post(
            uriTemplate: '/puzzles/attempts/{id}/submission',
            requirements: ['id' => self::UUID_PATTERN],
            priority: 10,
            status: 200,
            openapi: new Operation(summary: 'Submits the moves tried; the server computes the result. Once per attempt.'),
            input: SubmitAttemptInput::class,
            read: false,
            processor: SubmitAttemptProcessor::class,
        ),
    ],
)]
final class Attempt
{
    public const UUID_PATTERN = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';

    #[ApiProperty(identifier: true)]
    public string $id;
    /** pending, solved or failed */
    public string $status;
    public bool $rated;
    public \DateTimeImmutable $startedAt;
    public ?\DateTimeImmutable $submittedAt;
    public ?int $durationMs;
    public int $mistakes;
    public int $hintLevel;
    public bool $solutionShown;
    public ?float $ratingBefore = null;
    public ?float $ratingAfter = null;
    public ?float $ratingDelta = null;
    #[ApiProperty(genId: false)]
    public PuzzleView $puzzle;

    public static function from(AttemptEntity $attempt): self
    {
        $view = new self();
        $view->id = $attempt->getId()->toRfc4122();
        $view->status = $attempt->getStatus()->value;
        $view->rated = $attempt->isRated();
        $view->startedAt = $attempt->getStartedAt();
        $view->submittedAt = $attempt->getSubmittedAt();
        $view->durationMs = $attempt->getDurationMs();
        $view->mistakes = $attempt->getMistakes();
        $view->hintLevel = $attempt->getHintLevel();
        $view->solutionShown = $attempt->isSolutionShown();
        $change = $attempt->getRatingChange();
        if (null !== $change) {
            $view->ratingBefore = round($change->getRatingBefore(), 1);
            $view->ratingAfter = round($change->getRatingAfter(), 1);
            $view->ratingDelta = round($change->getRatingDelta(), 1);
        }
        $view->puzzle = PuzzleView::from($attempt->getPuzzle());

        return $view;
    }
}
