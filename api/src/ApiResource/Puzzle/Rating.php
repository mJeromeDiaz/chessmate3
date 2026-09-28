<?php

declare(strict_types=1);

namespace App\ApiResource\Puzzle;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation;
use App\Entity\Puzzle\Rating as RatingEntity;
use App\Puzzle\Rating\RatingCalculator;
use App\State\Puzzle\LichessImportProcessor;
use App\State\Puzzle\RatingProvider;

/**
 * The current user's puzzle rating (singleton resource, no id in the URI).
 */
#[ApiResource(
    shortName: 'PuzzleRating',
    // Every field is always present (null included): a stable contract for the SPA.
    normalizationContext: ['skip_null_values' => false],
    operations: [
        new Get(uriTemplate: '/puzzles/rating', priority: 10, provider: RatingProvider::class),
        new Post(
            uriTemplate: '/puzzles/rating/lichess-import',
            priority: 10,
            status: 200,
            openapi: new Operation(summary: 'Seeds the rating from the linked Lichess account, before the first rated puzzle.'),
            input: false,
            read: false,
            processor: LichessImportProcessor::class,
        ),
    ],
)]
final class Rating
{
    public int $rating;
    public int $deviation;
    /** True while the rating is still very uncertain (RD > 110): show it as "1500?". */
    public bool $provisional;
    public int $ratedCount;
    /** default or lichess */
    public string $source;
    /** The user may seed the rating from Lichess now (linked account, no rated puzzle yet). */
    public bool $lichessImportAvailable;

    public static function from(?RatingEntity $rating, bool $lichessImportAvailable): self
    {
        $state = $rating?->getState() ?? RatingCalculator::initial();
        $view = new self();
        $view->rating = (int) round($state->rating);
        $view->deviation = (int) round($state->deviation);
        $view->provisional = $rating?->isProvisional() ?? true;
        $view->ratedCount = $rating?->getRatedCount() ?? 0;
        $view->source = $rating?->getSource()->value ?? 'default';
        $view->lichessImportAvailable = $lichessImportAvailable;

        return $view;
    }
}
