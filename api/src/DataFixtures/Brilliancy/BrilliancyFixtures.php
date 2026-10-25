<?php

declare(strict_types=1);

namespace App\DataFixtures\Brilliancy;

use App\Brilliancy\Index\Indexer;
use App\DataFixtures\Puzzle\PuzzleFixtures;
use App\DataFixtures\Puzzle\SamplePuzzles;
use App\Repository\Catalog\PuzzleRepository;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

/**
 * Indexes the sample puzzles for « Brillant ou Blunder » (docs/BRILLIANCY.md, § 2), the only ones
 * a dev or e2e catalogue is sure to hold; a full catalogue is indexed by `app:brilliancy:rebuild`.
 */
final class BrilliancyFixtures extends Fixture implements DependentFixtureInterface
{
    public function __construct(
        private readonly Indexer $indexer,
        private readonly PuzzleRepository $puzzles,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        $ids = [];
        foreach (SamplePuzzles::create() as $sample) {
            $id = $this->puzzles->findOneByLichessId($sample->getLichessId())?->getId();
            if (null !== $id) {
                $ids[] = $id;
            }
        }
        $this->indexer->index($ids);
    }

    public function getDependencies(): array
    {
        return [PuzzleFixtures::class];
    }
}
