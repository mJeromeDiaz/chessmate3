<?php

declare(strict_types=1);

namespace App\ApiResource\Training;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\NotExposed;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation;
use App\ApiResource\Woodpecker\Set;
use App\State\Training\NextItemProcessor;
use App\State\Training\SubmitItemProcessor;
use App\Training\Run\Step;

/**
 * The state of a run after serving or resolving an item: the run (closed once time is up) and the
 * item to play, or the verdict on the submitted one.
 */
#[ApiResource(
    shortName: 'TrainingRunStep',
    normalizationContext: ['skip_null_values' => false],
    operations: [
        new Post(
            uriTemplate: '/training/runs/{id}/next',
            requirements: ['id' => Set::UUID_PATTERN],
            status: 200,
            openapi: new Operation(summary: 'The item to play (the one in progress after a reload); none once the run is closed.'),
            input: false,
            read: false,
            processor: NextItemProcessor::class,
            name: 'training_run_next',
        ),
        new Post(
            uriTemplate: '/training/runs/{id}/submission',
            requirements: ['id' => Set::UUID_PATTERN],
            status: 200,
            openapi: new Operation(summary: 'Submits what was tried on an item of the run; the server computes the result.'),
            input: SubmitItemInput::class,
            read: false,
            processor: SubmitItemProcessor::class,
            name: 'training_run_submission',
        ),
        // Item IRI only (answers 404), kept under the domain prefix.
        new NotExposed(uriTemplate: '/training/runs/{id}/step', requirements: ['id' => Set::UUID_PATTERN]),
    ],
)]
final class RunStep
{
    /** The run's id. */
    #[ApiProperty(identifier: true)]
    public string $id;
    #[ApiProperty(readableLink: true, genId: false)]
    public Run $run;
    #[ApiProperty(genId: false)]
    public ?ItemView $item;
    #[ApiProperty(genId: false)]
    public ?ItemResultView $result;

    public static function from(Step $step, \DateTimeImmutable $now): self
    {
        $view = new self();
        $view->id = $step->run->getId()->toRfc4122();
        $view->run = Run::from($step->run, $now);
        $view->item = null === $step->item ? null : ItemView::from($step->item);
        $view->result = null === $step->result ? null : ItemResultView::from($step->result);

        return $view;
    }
}
