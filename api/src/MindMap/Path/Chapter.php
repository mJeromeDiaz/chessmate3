<?php

declare(strict_types=1);

namespace App\MindMap\Path;

/**
 * A chapter of a path (docs/MIND_MAP.md, § 1): its sections, then its boss, announced at its top
 * and played at its end; beaten, it opens the next chapter.
 */
final readonly class Chapter
{
    /**
     * @param list<Section> $sections
     */
    public function __construct(
        public string $key,
        public int $number,
        public array $sections,
        public Step $boss,
    ) {
    }

    /**
     * @return list<Step>
     */
    public function allSteps(): array
    {
        $steps = [];
        foreach ($this->sections as $section) {
            array_push($steps, ...$section->allSteps());
        }
        $steps[] = $this->boss;

        return $steps;
    }
}
