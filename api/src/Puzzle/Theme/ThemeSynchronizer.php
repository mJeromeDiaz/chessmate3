<?php

declare(strict_types=1);

namespace App\Puzzle\Theme;

use App\Entity\Catalog\Theme;
use App\Repository\Catalog\ThemeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Upserts {@see ThemeCatalog} into `puzzle_theme`. Idempotent: existing rows keep their id (which
 * `puzzle_theme_membership` references) and their precomputed count.
 */
final class ThemeSynchronizer
{
    public function __construct(
        private ThemeRepository $themes,
        #[Autowire(service: 'doctrine.orm.catalog_entity_manager')]
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
