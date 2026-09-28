<?php

declare(strict_types=1);

namespace App\Puzzle\Theme;

use App\Entity\Puzzle\Theme;
use App\Repository\Puzzle\ThemeRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Upserts {@see ThemeCatalog} into `puzzle_theme`. Idempotent: existing rows keep their id (which
 * `puzzle_theme_membership` references) and their precomputed count.
 */
final class ThemeSynchronizer
{
    public function __construct(
        private ThemeRepository $themes,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return int number of themes created
     */
    public function sync(): int
    {
        $existing = [];
        foreach ($this->themes->findAll() as $theme) {
            $existing[$theme->getKey()] = $theme;
        }

        $created = 0;
        foreach (ThemeCatalog::THEMES as $position => [$key, $category, $labelEn, $labelFr, $descriptionEn, $descriptionFr]) {
            $theme = $existing[$key] ?? null;
            if (null === $theme) {
                $theme = new Theme($key);
                $this->entityManager->persist($theme);
                ++$created;
            }
            $theme->update($category, $labelEn, $labelFr, $descriptionEn, $descriptionFr, $position);
        }

        $this->entityManager->flush();

        return $created;
    }
}
