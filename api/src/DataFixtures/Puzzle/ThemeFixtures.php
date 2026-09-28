<?php

declare(strict_types=1);

namespace App\DataFixtures\Puzzle;

use App\Puzzle\Theme\ThemeSynchronizer;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

/**
 * The Lichess theme reference list (same source as `app:puzzle:sync-themes` in production).
 */
final class ThemeFixtures extends Fixture
{
    public function __construct(private readonly ThemeSynchronizer $synchronizer)
    {
    }

    public function load(ObjectManager $manager): void
    {
        $this->synchronizer->sync();
    }
}
