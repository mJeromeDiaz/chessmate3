<?php

declare(strict_types=1);

namespace App\Enum\Coordinates;

/**
 * The side at the bottom of the board in a coordinates series (docs/COORDINATES.md). Each one is
 * validated on its own. Stored as its value: add cases, never rename one.
 */
enum Orientation: string
{
    case White = 'white';
    case Black = 'black';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $orientation): string => $orientation->value, self::cases());
    }
}
