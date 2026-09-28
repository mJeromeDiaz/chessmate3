<?php

declare(strict_types=1);

namespace App\ApiResource\Puzzle;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\NotExposed;
use App\Entity\Puzzle\Theme as ThemeEntity;
use App\State\Puzzle\ThemeCollectionProvider;

/**
 * The puzzle themes in display order, with their category and precomputed puzzle count.
 */
#[ApiResource(
    shortName: 'PuzzleTheme',
    // Every field is always present (null included): a stable contract for the SPA.
    normalizationContext: ['skip_null_values' => false],
    operations: [
        new GetCollection(
            uriTemplate: '/puzzles/themes',
            priority: 10,
            paginationEnabled: false,
            cacheHeaders: ['max_age' => 300, 'public' => false],
            provider: ThemeCollectionProvider::class,
        ),
        // Item IRI only (API Platform needs one; it answers 404), kept under the domain prefix.
        new NotExposed(uriTemplate: '/puzzles/themes/{key}', requirements: ['key' => '[A-Za-z0-9]{1,32}'], priority: 10),
    ],
)]
final class Theme
{
    #[ApiProperty(identifier: true)]
    public string $key;
    public string $category;
    public string $categoryLabelFr;
    public string $categoryLabelEn;
    public string $labelFr;
    public string $labelEn;
    public string $descriptionFr;
    public string $descriptionEn;
    /** Selectable puzzles with this theme (precomputed after each import). */
    public int $puzzleCount;

    public static function from(ThemeEntity $theme): self
    {
        $view = new self();
        $view->key = $theme->getKey();
        $view->category = $theme->getCategory()->value;
        $view->categoryLabelFr = $theme->getCategory()->labelFr();
        $view->categoryLabelEn = $theme->getCategory()->labelEn();
        $view->labelFr = $theme->getLabelFr();
        $view->labelEn = $theme->getLabelEn();
        $view->descriptionFr = $theme->getDescriptionFr();
        $view->descriptionEn = $theme->getDescriptionEn();
        $view->puzzleCount = $theme->getPuzzleCount();

        return $view;
    }
}
