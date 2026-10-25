<?php

declare(strict_types=1);

namespace App\MindMap\Path;

/**
 * A chapter of a path (docs/MIND_MAP.md, § 1): its sections, then its boss, announced at its top
 * and played at its end; beaten, it opens the next chapter. A free chapter locks nothing: open
 * whatever came before, its steps playable in any order (the endgames path, docs/ENDGAMES.md).
 * A chapter outside the mastery does not count for its path's mastery badge (the défis' opening
 * chapters, one per repertoire of the player); $meta is what the client needs to name a chapter
 * its own texts cannot (a repertoire's name).
 */
final readonly class Chapter
{
    /**
     * @param list<Section>             $sections
     * @param array<string, mixed>|null $meta
     */
    public function __construct(
        public string $key,
        public int $number,
        public array $sections,
        public Step $boss,
        public bool $free = false,
        public bool $mastery = true,
        public ?array $meta = null,
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
