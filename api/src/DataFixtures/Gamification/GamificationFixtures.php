<?php

declare(strict_types=1);

namespace App\DataFixtures\Gamification;

use App\DataFixtures\Dashboard\ActivityHistoryFixtures;
use App\DataFixtures\DemoUserFixtures;
use App\DataFixtures\Repertoire\RepertoireFixtures;
use App\DataFixtures\Woodpecker\WoodpeckerFixtures;
use App\Entity\User;
use App\Gamification\Trophy\TrophyEvaluator;
use App\Gamification\Xp\XpRebuilder;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

/**
 * The demo user's XP, computed from the history the other fixtures wrote (docs/GAMIFICATION.md),
 * as `app:gamification:rebuild` does after a deployment.
 */
final class GamificationFixtures extends Fixture implements DependentFixtureInterface
{
    public function __construct(
        private readonly XpRebuilder $rebuilder,
        private readonly TrophyEvaluator $trophies,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        $manager->flush();
        $user = $this->getReference(DemoUserFixtures::REFERENCE, User::class);
        $this->rebuilder->rebuild($user);
        $this->trophies->rebuild($user);
    }

    public function getDependencies(): array
    {
        return [DemoUserFixtures::class, ActivityHistoryFixtures::class, WoodpeckerFixtures::class, RepertoireFixtures::class];
    }
}
