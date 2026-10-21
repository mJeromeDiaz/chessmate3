<?php

declare(strict_types=1);

namespace App\Enum\MindMap;

/**
 * A capacity of the mind map that has a path (docs/MIND_MAP.md): the other cards of the brain
 * have none yet. Stored as its value: add cases, never rename one.
 */
enum Capacity: string
{
    case Visualisation = 'visualisation';
    case Calculation = 'calculation';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $capacity): string => $capacity->value, self::cases());
    }
}
