<?php

declare(strict_types=1);

namespace App\Enum\Training;

/**
 * The distractions a mind map boss brings on screen (docs/MIND_MAP.md, § 2): none in the exercises,
 * stronger chapter after chapter in the bosses. A module only passes it on to its item. Stored as
 * its value in a run's config: add cases, never rename one.
 */
enum Distraction: string
{
    case None = 'none';
    /** The board wobbles now and then, pieces drift across it. */
    case Light = 'light';
    /** More often, and a stray sound while moves are announced. */
    case Medium = 'medium';
    /** Stray sounds, animations on the board, a countdown in sight (the Gargouille). */
    case Strong = 'strong';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $d): string => $d->value, self::cases());
    }
}
