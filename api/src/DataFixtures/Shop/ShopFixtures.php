<?php

declare(strict_types=1);

namespace App\DataFixtures\Shop;

use App\DataFixtures\DemoUserFixtures;
use App\Entity\User;
use App\Shop\Catalog\DefaultCatalog;
use App\Shop\Wallet\CrownAdjustment;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ObjectManager;

/**
 * The store's default catalogue (the fixtures purge the one the migration installed) and crowns
 * for the demo user, to try the store (docs/SHOP.md).
 */
final class ShopFixtures extends Fixture implements DependentFixtureInterface
{
    public const DEMO_CROWNS = 1000;

    public function __construct(
        private readonly Connection $connection,
        private readonly CrownAdjustment $crowns,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        $manager->flush();
        foreach (DefaultCatalog::statements(new \DateTimeImmutable()) as [$sql, $params]) {
            $this->connection->executeStatement($sql, $params);
        }
        $user = $this->getReference(DemoUserFixtures::REFERENCE, User::class);
        $this->crowns->adjust($user->getId(), self::DEMO_CROWNS, 'Cristaux de démonstration', null);
    }

    public function getDependencies(): array
    {
        return [DemoUserFixtures::class];
    }
}
