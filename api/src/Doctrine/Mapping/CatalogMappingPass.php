<?php

declare(strict_types=1);

namespace App\Doctrine\Mapping;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

/**
 * Keeps the puzzle catalogue (`App\Entity\Catalog`, its own database: docs/DEPLOY_OVH.md, § 3) out
 * of the default entity manager, whose `App` mapping covers all of `src/Entity`. DoctrineBundle has
 * no exclusion setting, hence this pass:
 * - the attribute driver skips the directory, so schema tools and `migrations:diff` on the main
 *   database ignore the catalogue tables;
 * - a {@see TransientDriver} answers first for the namespace, so the default manager reports those
 *   classes as not mapped and the registry hands them to the `catalog` manager.
 */
final class CatalogMappingPass implements CompilerPassInterface
{
    public const string NAMESPACE = 'App\Entity\Catalog';

    public function process(ContainerBuilder $container): void
    {
        $container->getDefinition('doctrine.orm.default_attribute_metadata_driver')
            ->addMethodCall('addExcludePaths', [[$container->getParameter('kernel.project_dir').'/src/Entity/Catalog']]);

        $chain = $container->getDefinition('doctrine.orm.default_metadata_driver');
        $chain->setMethodCalls([
            ['addDriver', [new Definition(TransientDriver::class), self::NAMESPACE]],
            ...$chain->getMethodCalls(),
        ]);
    }
}
