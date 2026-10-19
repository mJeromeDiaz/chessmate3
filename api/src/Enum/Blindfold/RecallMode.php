<?php

declare(strict_types=1);

namespace App\Enum\Blindfold;

/**
 * How a position recall run is played (docs/BLINDFOLD_RECALL.md). Stored as its value: add cases,
 * never rename one.
 */
enum RecallMode: string
{
    /** The recall page: positions one after the other, no clock shown (a long hidden budget). */
    case Free = 'free';
    /** A timed run, from the page or a session step. */
    case Timed = 'timed';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $mode): string => $mode->value, self::cases());
    }
}
