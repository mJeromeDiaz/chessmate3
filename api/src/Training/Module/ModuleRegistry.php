<?php

declare(strict_types=1);

namespace App\Training\Module;

use App\Enum\Training\Module;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * The implementation of each module ({@see TimeboxedModuleInterface}, autoconfigured tag).
 */
final class ModuleRegistry
{
    /** @var array<string, TimeboxedModuleInterface> by module value */
    private array $byModule = [];

    /**
     * @param iterable<TimeboxedModuleInterface> $modules
     */
    public function __construct(
        #[AutowireIterator(TimeboxedModuleInterface::TAG)]
        iterable $modules,
    ) {
        foreach ($modules as $module) {
            $this->byModule[$module->module()->value] = $module;
        }
    }

    /**
     * @throws \LogicException when no implementation handles the module
     */
    public function for(Module $module): TimeboxedModuleInterface
    {
        return $this->byModule[$module->value] ?? throw new \LogicException(sprintf('No implementation of the %s module.', $module->value));
    }
}
