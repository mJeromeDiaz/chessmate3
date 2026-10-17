<?php

declare(strict_types=1);

namespace App\Enum\Parcours;

/**
 * What a parcours trains (docs/PARCOURS.md): its five tiers come from {@see \App\Parcours\ParcoursRules}.
 * Stored as its value: add cases, never rename one.
 */
enum Theme: string
{
    /** Mate in 1, then 2… up to 5. */
    case Mates = 'mates';
    /** A repertoire's lines and its opening's puzzles, in turn. */
    case Opening = 'opening';
    /** One kind of endgame per tier. */
    case Endgames = 'endgames';
    /** One tactical motif per tier. */
    case Tactics = 'tactics';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $theme): string => $theme->value, self::cases());
    }
}
