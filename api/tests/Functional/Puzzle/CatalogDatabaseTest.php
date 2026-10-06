<?php

declare(strict_types=1);

namespace App\Tests\Functional\Puzzle;

use App\Doctrine\Mapping\CatalogMappingPass;
use App\Entity\Catalog\Puzzle;
use App\Entity\Catalog\Theme;
use App\Entity\Catalog\ThemeMembership;
use App\Entity\Puzzle\Attempt;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\Mapping\MappingException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The puzzle catalogue in its own database (docs/DEPLOY_OVH.md, § 3): its entities belong to the
 * `catalog` entity manager only, and nothing of the main database maps or links to them.
 */
final class CatalogDatabaseTest extends KernelTestCase
{
    private const CATALOG_CLASSES = [Puzzle::class, Theme::class, ThemeMembership::class];

    private EntityManagerInterface $default;
    private EntityManagerInterface $catalog;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->default = self::getContainer()->get('doctrine.orm.default_entity_manager');
        $this->catalog = self::getContainer()->get('doctrine.orm.catalog_entity_manager');
    }

    public function testTheTwoManagersUseTwoDatabases(): void
    {
        $main = $this->default->getConnection()->fetchOne('SELECT DATABASE()');
        $catalog = $this->catalog->getConnection()->fetchOne('SELECT DATABASE()');

        self::assertIsString($main);
        self::assertIsString($catalog);
        self::assertNotSame($main, $catalog);
        self::assertSame($catalog, self::getContainer()->get('doctrine.dbal.catalog_connection')->fetchOne('SELECT DATABASE()'));
        self::assertSame($main, self::getContainer()->get(Connection::class)->fetchOne('SELECT DATABASE()'), 'the autowired connection is the main one');
    }

    public function testTheRegistryHandsTheCatalogueClassesToTheCatalogManager(): void
    {
        $registry = self::getContainer()->get(ManagerRegistry::class);
        foreach (self::CATALOG_CLASSES as $class) {
            self::assertSame($this->catalog, $registry->getManagerForClass($class), $class);
        }
        self::assertSame($this->default, $registry->getManagerForClass(Attempt::class));
    }

    public function testEachManagerMapsItsOwnClassesOnly(): void
    {
        $catalogClasses = array_map(static fn (ClassMetadata $metadata): string => $metadata->getName(), $this->catalog->getMetadataFactory()->getAllMetadata());
        sort($catalogClasses);
        $expected = self::CATALOG_CLASSES;
        sort($expected);
        self::assertSame($expected, $catalogClasses);

        foreach ($this->default->getMetadataFactory()->getAllMetadata() as $metadata) {
            self::assertStringStartsNotWith(CatalogMappingPass::NAMESPACE, $metadata->getName());
            foreach ($metadata->getAssociationMappings() as $field => $association) {
                self::assertStringStartsNotWith(CatalogMappingPass::NAMESPACE, $association->targetEntity, \sprintf('%s::$%s links to the catalogue: keep its id instead.', $metadata->getName(), $field));
            }
        }
    }

    public function testTheDefaultManagerRefusesACataloguePuzzle(): void
    {
        $this->expectException(MappingException::class);

        $this->default->persist(new Puzzle('zz999', '8/8/8/8/8/8/8/K6k w - - 0 1', 'a1a2 h1h2', 1500, 80, 90, 1000, ['short'], 'https://lichess.org/test'));
    }
}
