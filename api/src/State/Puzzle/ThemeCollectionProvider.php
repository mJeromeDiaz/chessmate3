<?php

declare(strict_types=1);

namespace App\State\Puzzle;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Puzzle\Theme;
use App\Repository\Catalog\ThemeRepository;

/**
 * GET /puzzles/themes: ~75 rows read by primary key order, counts precomputed: no COUNT here.
 *
 * @implements ProviderInterface<Theme>
 */
final class ThemeCollectionProvider implements ProviderInterface
{
    public function __construct(private readonly ThemeRepository $themes)
    {
    }

    /**
     * @return list<Theme>
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        return array_map(Theme::from(...), $this->themes->findAllOrdered());
    }
}
