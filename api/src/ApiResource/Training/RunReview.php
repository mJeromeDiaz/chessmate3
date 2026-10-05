<?php

declare(strict_types=1);

namespace App\ApiResource\Training;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model\Operation;
use App\ApiResource\Woodpecker\Set;
use App\Entity\Training\Run as RunEntity;
use App\State\Training\RunReviewProvider;
use App\Training\Module\ReviewItem;

/**
 * The review of a closed run (docs/TRAINING.md, run review): each item played, its outcome and
 * what the client needs to replay it on its own. 409 while the run is active, 404 for another
 * user's run.
 */
#[ApiResource(
    shortName: 'TrainingRunReview',
    normalizationContext: ['skip_null_values' => false],
    operations: [
        new Get(
            uriTemplate: '/training/runs/{id}/review',
            requirements: ['id' => Set::UUID_PATTERN],
            openapi: new Operation(summary: 'The items of a closed run, for its end-of-run review.'),
            provider: RunReviewProvider::class,
            name: 'training_run_review',
        ),
    ],
)]
final class RunReview
{
    #[ApiProperty(identifier: true)]
    public string $id;
    public string $module;
    /**
     * @var list<array{index: int, type: string, status: string, durationMs: int|null, data: array<string, mixed>}>
     *   in the order played; status ok, hint (no wrong move, help asked) or fail
     */
    public array $items;
    /** XP gained in the run so far (docs/GAMIFICATION.md; written by the worker, possibly a moment later). */
    public int $xp = 0;

    /**
     * @param list<ReviewItem> $items
     */
    public static function from(RunEntity $run, array $items): self
    {
        $view = new self();
        $view->id = $run->getId()->toRfc4122();
        $view->module = $run->getModule()->value;
        $view->items = array_map(static fn (ReviewItem $item, int $index): array => [
            'index' => $index + 1,
            'type' => $item->type,
            'status' => $item->status,
            'durationMs' => $item->durationMs,
            'data' => $item->data,
        ], $items, array_keys($items));

        return $view;
    }
}
