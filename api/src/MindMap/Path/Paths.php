<?php

declare(strict_types=1);

namespace App\MindMap\Path;

use App\Enum\MindMap\Capacity;

/**
 * The paths of the mind map, by capacity (docs/MIND_MAP.md), and their steps by key.
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
        };
    }

    /**
     * The badge earned once every exercise of the path is completed, null when the path has none.
     */
    public static function masteryBadge(Capacity $capacity): ?string
    {
        return Capacity::Endgames === $capacity ? Endgames::MASTERY_BADGE : null;
    }

    /**
     * A step of the path and the chapter it belongs to, null for an unknown key.
     *
     * @return array{chapter: Chapter, step: Step}|null
     */
    public static function find(Capacity $capacity, string $key): ?array
    {
        foreach (self::chapters($capacity) as $chapter) {
            foreach ($chapter->allSteps() as $step) {
                if ($step->key === $key) {
                    return ['chapter' => $chapter, 'step' => $step];
                }
            }
        }

        return null;
    }
}
