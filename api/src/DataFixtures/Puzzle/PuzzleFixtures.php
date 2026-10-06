<?php

declare(strict_types=1);

namespace App\DataFixtures\Puzzle;

use App\Entity\Catalog\Puzzle;
use App\Puzzle\Selection\SelectionRebuilder;
use App\Repository\Catalog\PuzzleRepository;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The sample puzzles ({@see SamplePuzzles}), indexed for selection. The catalogue has its own
 * database (docs/DEPLOY_OVH.md, § 3), which `doctrine:fixtures:load` never purges: only the missing
 * samples are added, so puzzle ids stay stable and an imported catalogue is left untouched.
 */
final class PuzzleFixtures extends Fixture implements DependentFixtureInterface
{
    public function __construct(
        private readonly SelectionRebuilder $rebuilder,
        private readonly PuzzleRepository $puzzles,
        #[Autowire(service: 'doctrine.orm.catalog_entity_manager')]
        private readonly EntityManagerInterface $catalog,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        $added = [];
        foreach (SamplePuzzles::create() as $puzzle) {
            if (null === $this->puzzles->findOneByLichessId($puzzle->getLichessId())) {
                $this->catalog->persist($puzzle);
                $added[] = $puzzle;
            }
        }
        $this->catalog->flush();

        $this->rebuilder->addPuzzles(array_map(static fn (Puzzle $puzzle): int => (int) $puzzle->getId(), $added));
        // The rebuilder writes in plain SQL (selectable flag, theme counts).
        $this->catalog->clear();
    }

    public function getDependencies(): array
    {
        return [ThemeFixtures::class];
    }
}
