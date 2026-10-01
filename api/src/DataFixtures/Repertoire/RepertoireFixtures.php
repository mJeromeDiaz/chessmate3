<?php

declare(strict_types=1);

namespace App\DataFixtures\Repertoire;

use App\DataFixtures\DemoUserFixtures;
use App\Entity\User;
use App\Enum\Repertoire\Color;
use App\Repertoire\Import\ImportApplier;
use App\Repertoire\Import\PgnAnalyzer;
use App\Repertoire\Opening\OpeningSynchronizer;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

/**
 * The opening names (the purge of fixtures:load empties them) and two repertoires of the demo
 * user, imported like a user would (docs/REPERTOIRE.md, "Données de démonstration"):
 *
 * - White, 1.e4: variations against 1...c5, 1...e6, 1...c6, comments and a NAG;
 * - Black against 1.d4: a transposition by White's move order (1.d4 Nf6 2.Nf3 e6 3.c4 d5 joins
 *   1.d4 Nf6 2.c4 e6 3.Nf3 d5).
 */
final class RepertoireFixtures extends Fixture implements DependentFixtureInterface
{
    private const REPERTOIRES = [
        'white-e4.pgn' => Color::White,
        'black-d4.pgn' => Color::Black,
    ];

    public function __construct(
        private readonly OpeningSynchronizer $openings,
        private readonly PgnAnalyzer $analyzer,
        private readonly ImportApplier $applier,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        $this->openings->sync();
        $user = $this->getReference(DemoUserFixtures::REFERENCE, User::class);
        foreach (self::REPERTOIRES as $file => $color) {
            $tree = $this->analyzer->analyze((string) file_get_contents(__DIR__.'/data/'.$file));
            $this->applier->apply($user, $tree, ['name' => (string) $tree->name, 'color' => $color]);
        }
    }

    public function getDependencies(): array
    {
        return [DemoUserFixtures::class];
    }
}
