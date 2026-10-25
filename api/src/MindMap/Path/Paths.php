<?php

declare(strict_types=1);

namespace App\MindMap\Path;

use App\Enum\MindMap\Capacity;

/**
 * The paths of the mind map, by capacity (docs/MIND_MAP.md), and their steps by key: their fixed
 * chapters. The défis' opening chapters depend on the player ({@see \App\MindMap\Progress\PathCatalog});
 * here, their steps are found without their activity (kind, passes, XP: what the XP rebuild needs).
 */
final class Paths
{
    /** @var array<string, list<Chapter>> */
    private static array $chapters = [];

    /**
     * @return list<Chapter>
     */
    public static function chapters(Capacity $capacity): array
    {
        return self::$chapters[$capacity->value] ??= match ($capacity) {
            Capacity::Visualisation => Visualisation::chapters(),
            Capacity::Calculation => Calculation::chapters(),
            Capacity::Endgames => Endgames::chapters(),
            Capacity::Challenges => Challenges::chapters(),
        };
    }

    /**
     * The badge earned once every exercise of the path's mastery chapters is completed, null when
     * the path has none.
     */
    public static function masteryBadge(Capacity $capacity): ?string
    {
        return match ($capacity) {
            Capacity::Endgames => Endgames::MASTERY_BADGE,
            Capacity::Challenges => Challenges::MASTERY_BADGE,
            default => null,
        };
    }

    /**
     * A step of the path and the chapter it belongs to, null for an unknown key.
     *
     * @return array{chapter: Chapter, step: Step}|null
     */
    public static function find(Capacity $capacity, string $key): ?array
    {
        $found = self::findIn(self::chapters($capacity), $key);
        $opening = null === $found && Capacity::Challenges === $capacity ? Challenges::parseOpening($key) : null;

        return null === $opening ? $found : self::findIn([Challenges::opening($opening['code'], 0, null)], $key);
    }

    /**
     * @param list<Chapter> $chapters
     *
     * @return array{chapter: Chapter, step: Step}|null
     */
    public static function findIn(array $chapters, string $key): ?array
    {
        foreach ($chapters as $chapter) {
            foreach ($chapter->allSteps() as $step) {
                if ($step->key === $key) {
                    return ['chapter' => $chapter, 'step' => $step];
                }
            }
        }

        return null;
    }
}
