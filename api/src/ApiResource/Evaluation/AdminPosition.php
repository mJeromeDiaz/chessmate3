<?php

declare(strict_types=1);

namespace App\ApiResource\Evaluation;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\Parameter;
use App\ApiResource\Woodpecker\Set;
use App\Entity\Evaluation\Position;
use App\Evaluation\EvaluationRules;
use App\State\Evaluation\AdminPositionProcessor;
use App\State\Evaluation\AdminPositionProvider;

/**
 * A position to evaluate, for admins only (docs/EVALUATION.md): entered by hand, deactivated once
 * played (deleting it is refused then).
 */
#[ApiResource(
    shortName: 'EvaluationAdminPosition',
    security: "is_granted('ROLE_ADMIN')",
    normalizationContext: ['skip_null_values' => false],
    operations: [
        new GetCollection(
            uriTemplate: '/admin/evaluation/positions',
            paginationItemsPerPage: 20,
            paginationMaximumItemsPerPage: 100,
            paginationClientItemsPerPage: true,
            openapi: new Operation(
                summary: 'Every position, newest first.',
                parameters: [
                    new Parameter('active', 'query', 'true or false', schema: ['type' => 'boolean']),
                    new Parameter('tag', 'query', 'opening, middlegame, structure or endgame', schema: ['type' => 'string']),
                ],
            ),
            provider: AdminPositionProvider::class,
        ),
        new Get(uriTemplate: '/admin/evaluation/positions/{id}', requirements: ['id' => Set::UUID_PATTERN], provider: AdminPositionProvider::class),
        new Post(uriTemplate: '/admin/evaluation/positions', input: PositionInput::class, processor: AdminPositionProcessor::class),
        new Put(uriTemplate: '/admin/evaluation/positions/{id}', requirements: ['id' => Set::UUID_PATTERN], input: PositionInput::class, read: false, processor: AdminPositionProcessor::class),
        new Delete(
            uriTemplate: '/admin/evaluation/positions/{id}',
            requirements: ['id' => Set::UUID_PATTERN],
            openapi: new Operation(summary: 'Deletes a position never played (409 otherwise: deactivate it).'),
            read: false,
            processor: AdminPositionProcessor::class,
        ),
    ],
)]
final class AdminPosition
{
    #[ApiProperty(identifier: true)]
    public string $id = '';
    public string $fen = '';
    /** white or black: the side to move */
    public string $turn = 'white';
    public int $evalCp = 0;
    /** The evaluation as shown to players: "+1,4", "+−"… */
    public string $engine = '';
    /** -2 to 2 */
    public int $category = 0;
    /** Within 0.2 of a category's border: the answer would be a coin toss. */
    public bool $nearBorder = false;
    public ?string $plan = null;
    /** @var list<string> */
    public array $ideas = [];
    public ?string $tip = null;
    public ?string $tag = null;
    public int $rating = EvaluationRules::DEFAULT_RATING;
    public ?string $source = null;
    public bool $active = true;
    /** Times it was answered. */
    public int $played = 0;
    public ?\DateTimeImmutable $createdAt = null;
    public ?\DateTimeImmutable $updatedAt = null;

    public static function from(Position $position, int $played): self
    {
        $view = new self();
        $view->id = $position->getId()->toRfc4122();
        $view->fen = $position->getFen();
        $view->turn = $position->getTurn()->value;
        $view->evalCp = $position->getEvalCp();
        $view->engine = EvaluationRules::label($position->getEvalCp());
        $view->category = EvaluationRules::category($position->getEvalCp());
        $view->nearBorder = EvaluationRules::nearBorder($position->getEvalCp());
        $view->plan = $position->getPlan()?->value;
        $view->ideas = $position->getIdeas();
        $view->tip = $position->getTip();
        $view->tag = $position->getTag()?->value;
        $view->rating = $position->getRating();
        $view->source = $position->getSource();
        $view->active = $position->isActive();
        $view->played = $played;
        $view->createdAt = $position->getCreatedAt();
        $view->updatedAt = $position->getUpdatedAt();

        return $view;
    }
}
