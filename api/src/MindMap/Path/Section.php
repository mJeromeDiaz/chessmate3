<?php

declare(strict_types=1);

namespace App\MindMap\Path;

/**
 * A section of a chapter (docs/MIND_MAP.md, § 1): its exercises (and chests) in order, then its
 * mini-bosses in order, then its hidden bosses. A chapter without sections has one, numberless.
 */
final readonly class Section
{
    /**
     * @param list<Step> $steps      exercises and chests, in order
     * @param list<Step> $miniBosses all to beat, in order, to clear the section
     * @param list<Step> $hidden     revealed once the section is cleared, optional
     */
    public function __construct(
        public string $key,
        public ?string $number,
        public array $steps,
        public array $miniBosses = [],
        public array $hidden = [],
    ) {
    }

    /**
     * @return list<Step>
     */
    public function allSteps(): array
    {
        return [...$this->steps, ...$this->miniBosses, ...$this->hidden];
    }
}
