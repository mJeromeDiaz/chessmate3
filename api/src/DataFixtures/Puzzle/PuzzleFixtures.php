<?php

declare(strict_types=1);

namespace App\DataFixtures\Puzzle;

use App\Puzzle\Selection\SelectionRebuilder;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

/**
 * The sample puzzles ({@see SamplePuzzles}), indexed for selection.
 */
final class PuzzleFixtures extends Fixture implements DependentFixtureInterface
{
    public function __construct(private readonly SelectionRebuilder $rebuilder)
    {
    }

    public function load(ObjectManager $manager): void
    {
        $ids = [];
        $puzzles = SamplePuzzles::create();
        foreach ($puzzles as $puzzle) {
            $manager->persist($puzzle);
        }
        $manager->flush();

        foreach ($puzzles as $puzzle) {
            $ids[] = (int) $puzzle->getId();
        }
        $this->rebuilder->addPuzzles($ids);
    }

    public function getDependencies(): array
    {
        return [ThemeFixtures::class];
    }
}
