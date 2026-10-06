<?php

declare(strict_types=1);

namespace App\ApiResource\Puzzle;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\Entity\Catalog\Puzzle as PuzzleEntity;
use App\State\Puzzle\PuzzleProvider;

/**
 * Public facts about a puzzle, by Lichess id. No solution here: the solution only travels with an
 * attempt. `{id}` is exactly 5 alphanumerics so that sibling routes (/puzzles/themes,
 * /puzzles/attempts, /puzzles/rating) can never be captured by this one.
 */
#[ApiResource(
    shortName: 'Puzzle',
    // Every field is always present (null included): a stable contract for the SPA.
    normalizationContext: ['skip_null_values' => false],
    operations: [
        new Get(
            uriTemplate: '/puzzles/{id}',
            requirements: ['id' => '[A-Za-z0-9]{5}'],
            provider: PuzzleProvider::class,
        ),
    ],
)]
final class Puzzle
{
    #[ApiProperty(identifier: true)]
    public string $id;
    public int $rating;
    public int $popularity;
    public int $nbPlays;
    /** @var list<string> */
    public array $themes;
    public string $gameUrl;

    public static function from(PuzzleEntity $puzzle): self
    {
        $view = new self();
        $view->id = $puzzle->getLichessId();
        $view->rating = $puzzle->getRating();
        $view->popularity = $puzzle->getPopularity();
        $view->nbPlays = $puzzle->getNbPlays();
        $view->themes = $puzzle->getThemes();
        $view->gameUrl = $puzzle->getGameUrl();

        return $view;
    }
}
