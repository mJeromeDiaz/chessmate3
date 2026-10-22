<?php

declare(strict_types=1);

namespace App\Doctrine\Mapping;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

/**
 * Keeps the entities of a database of their own out of the default entity manager, whose `App`
 * mapping covers all of `src/Entity` (docs/DEPLOY_OVH.md, § 3): the puzzle catalogue
 * (`App\Entity\Catalog`) and the endgame tablebase (`App\Entity\Tablebase`). DoctrineBundle has no
 * exclusion setting, hence this pass, registered once per database:
 * - the attribute driver skips the directory, so schema tools and `migrations:diff` on the main
 *   database ignore those tables;
 * - a {@see TransientDriver} answers first for the namespace, so the default manager reports those
 *   classes as not mapped and the registry hands them to their own manager.
 */
final readonly class SeparateDatabaseMappingPass implements CompilerPassInterface
{
    public const string CATALOG = 'App\Entity\Catalog';
    public const string TABLEBASE = 'App\Entity\Tablebase';

    /**
     * @param string $namespace one of the constants: its directory is `src/Entity/<last segment>`
     */
    public function __construct(
        private string $namespace,
    ) {
    }

    public function process(ContainerBuilder $container): void
    {
        $directory = $container->getParameter('kernel.project_dir').'/src/Entity/'.substr($this->namespace, \strlen('App\Entity\\'));
        $container->getDefinition('doctrine.orm.default_attribute_metadata_driver')
            ->addMethodCall('addExcludePaths', [[$directory]]);

        $chain = $container->getDefinition('doctrine.orm.default_metadata_driver');
        $chain->setMethodCalls([
            ['addDriver', [new Definition(TransientDriver::class), $this->namespace]],
            ...$chain->getMethodCalls(),
        ]);
    }
}
