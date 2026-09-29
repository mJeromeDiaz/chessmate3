<?php

declare(strict_types=1);

namespace App\Woodpecker\Mode;

use App\Entity\Woodpecker\Set;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * The progression of each mode ({@see ProgressionInterface}, autoconfigured tag).
 */
final class ProgressionRegistry
{
    /** @var array<string, ProgressionInterface> by mode value */
    private array $byMode = [];

    /**
     * @param iterable<ProgressionInterface> $progressions
     */
    public function __construct(
        #[AutowireIterator(ProgressionInterface::TAG)]
        iterable $progressions,
    ) {
        foreach ($progressions as $progression) {
            $this->byMode[$progression->mode()->value] = $progression;
        }
    }

    /**
     * @throws \LogicException when no progression handles the set's mode
     */
    public function for(Set $set): ProgressionInterface
    {
        return $this->byMode[$set->getMode()->value]
            ?? throw new \LogicException(sprintf('No progression for the %s mode.', $set->getMode()->value));
    }
}
