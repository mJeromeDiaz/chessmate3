<?php

declare(strict_types=1);

namespace App\Doctrine\Mapping;

use Doctrine\ORM\Mapping\MappingException;
use Doctrine\Persistence\Mapping\ClassMetadata;
use Doctrine\Persistence\Mapping\Driver\MappingDriver;

/**
 * Maps nothing: registered first in the default entity manager's driver chain for the namespace of
 * a database of its own ({@see SeparateDatabaseMappingPass}), so that manager never claims one of
 * its entities. The registry then resolves those classes to their own manager, and persisting one
 * through the default manager fails instead of writing to the wrong database.
 */
final class TransientDriver implements MappingDriver
{
    public function loadMetadataForClass(string $className, ClassMetadata $metadata): void
    {
        throw MappingException::classIsNotAValidEntityOrMappedSuperClass($className);
    }

    public function getAllClassNames(): array
    {
        return [];
    }

    public function isTransient(string $className): bool
    {
        return true;
    }
}
