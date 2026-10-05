<?php

declare(strict_types=1);

namespace App\Enum\Training;

/**
 * A module that can be played in timed runs (docs/TRAINING.md). Stored as its value: add cases,
 * never rename one.
 */
enum Module: string
{
    case Woodpecker = 'woodpecker';
    case Repertoire = 'repertoire';
    case Puzzles = 'puzzles';
    /** Free study (a book, a video...): a timer, nothing to submit. */
    case Free = 'free';

    /** Its name for the user (emails, calendar). */
    public function label(): string
    {
        return match ($this) {
            self::Woodpecker => 'Woodpecker',
            self::Repertoire => 'Répertoire',
            self::Puzzles => 'Puzzles',
            self::Free => 'Libre',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $module): string => $module->value, self::cases());
    }
}
