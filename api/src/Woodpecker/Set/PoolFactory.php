<?php

declare(strict_types=1);

namespace App\Woodpecker\Set;

use App\Entity\Woodpecker\Set;
use App\Repository\Catalog\ThemeRepository;

/**
 * The {@see Pool} of a set, or of the themes asked at its creation.
 */
final class PoolFactory
{
    public function __construct(private readonly ThemeRepository $themes)
    {
    }

    /**
     * @param list<string> $keys theme keys
     *
     * @throws \InvalidArgumentException unknown theme key
     */
    public function ofThemes(array $keys): Pool
    {
        $themes = $this->themes->findByKeys($keys);
        if (\count($themes) !== \count(array_unique($keys))) {
            throw new \InvalidArgumentException('Unknown theme.');
        }

        return Pool::themes(array_map(static fn ($theme): int => (int) $theme->getId(), $themes));
    }

    /**
     * Where a set draws more puzzles from: null when its themes are gone since its creation (never
     * widen to "any theme" silently).
     */
    public function ofSet(Set $set): ?Pool
    {
        $opening = $set->getOpening();
        if (null !== $opening) {
            return Pool::opening($opening);
        }
        $themeIds = array_map(static fn ($theme): int => (int) $theme->getId(), $this->themes->findByKeys($set->getThemes()));

        return [] === $themeIds && [] !== $set->getThemes() ? null : Pool::themes($themeIds);
    }
}
