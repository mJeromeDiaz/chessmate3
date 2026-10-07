<?php

declare(strict_types=1);

namespace App\ApiResource\Woodpecker;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\NotExposed;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation;
use App\ApiResource\Puzzle\PuzzleView;
use App\ApiResource\Puzzle\SubmitAttemptInput;
use App\Entity\Catalog\Puzzle;
use App\Entity\Woodpecker\Attempt as AttemptEntity;
use App\State\Woodpecker\NextAttemptProcessor;
use App\State\Woodpecker\SubmitAttemptProcessor;

/**
 * A puzzle of the current cycle run. The solution travels with it (instant feedback, as in
 * Phase 2); the outcome is computed by the server on submission.
 */
#[ApiResource(
    shortName: 'WoodpeckerAttempt',
    normalizationContext: ['skip_null_values' => false],
    operations: [
        new Post(
            uriTemplate: '/woodpecker/sets/{setId}/attempts',
            uriVariables: ['setId' => new Link(fromClass: Set::class, identifiers: ['id'])],
            requirements: ['setId' => Set::UUID_PATTERN],
            openapi: new Operation(summary: 'The next puzzle of the current cycle (or the pending one).'),
            input: false,
            read: false,
            processor: NextAttemptProcessor::class,
        ),
        new Post(
            uriTemplate: '/woodpecker/attempts/{id}/submission',
            requirements: ['id' => Set::UUID_PATTERN],
            status: 200,
            openapi: new Operation(summary: 'Submits the moves tried; the server computes the result. Once per attempt.'),
            input: SubmitAttemptInput::class,
            read: false,
            processor: SubmitAttemptProcessor::class,
        ),
        // Item IRI only (answers 404), kept under the domain prefix.
        new NotExposed(uriTemplate: '/woodpecker/attempts/{id}', requirements: ['id' => Set::UUID_PATTERN]),
    ],
)]
final class Attempt
{
    #[ApiProperty(identifier: true)]
    public string $id;
    /** pending, solved or failed */
    public string $status;
    public int $mistakes;
    public int $hintLevel;
    public bool $solutionShown;
    public ?int $durationMs;
    /** Submission only: XP this attempt gains, daily cap included; null elsewhere. */
    public ?int $xp = null;
    #[ApiProperty(genId: false)]
    public PuzzleView $puzzle;
    /** The set after this request (progress, current run, cycle runs), embedded. */
    #[ApiProperty(readableLink: true, genId: false)]
    public Set $set;

    public static function from(AttemptEntity $attempt, Puzzle $puzzle, Set $set): self
    {
        $view = new self();
        $view->id = $attempt->getId()->toRfc4122();
        $view->status = $attempt->getStatus()->value;
        $view->mistakes = $attempt->getMistakes();
        $view->hintLevel = $attempt->getHintLevel();
        $view->solutionShown = $attempt->isSolutionShown();
        $view->durationMs = $attempt->getDurationMs();
        $view->puzzle = PuzzleView::from($puzzle);
        $view->set = $set;

        return $view;
    }
}
