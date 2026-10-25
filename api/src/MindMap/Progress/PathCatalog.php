<?php

declare(strict_types=1);

namespace App\MindMap\Progress;

use App\Challenge\Path\OpeningChapters;
use App\Entity\User;
use App\Enum\MindMap\Capacity;
use App\MindMap\Path\Chapter;
use App\MindMap\Path\Paths;
use App\MindMap\Path\Step;

/**
 * A player's path (docs/MIND_MAP.md, § 1): its fixed chapters ({@see Paths}), then, on the défis'
 * path, the player's opening chapters ({@see OpeningChapters}, docs/DEFIS.md).
 */
final readonly class PathCatalog
{
    public function __construct(
        private OpeningChapters $openings,
    ) {
    }

    /**
     * @return list<Chapter>
     */
    public function chapters(User $user, Capacity $capacity): array
    {
        $chapters = Paths::chapters($capacity);

        return Capacity::Challenges === $capacity ? [...$chapters, ...$this->openings->of($user)] : $chapters;
    }

    /**
     * A step of the player's path and its chapter, null for an unknown key (an opening chapter's
     * whose repertoire is gone).
     *
     * @return array{chapter: Chapter, step: Step}|null
     */
    public function find(User $user, Capacity $capacity, string $key): ?array
    {
        $found = Paths::findIn(Paths::chapters($capacity), $key);
        $opening = null === $found && Capacity::Challenges === $capacity ? $this->openings->find($user, $key) : null;

        return null === $opening ? $found : Paths::findIn([$opening], $key);
    }
}
