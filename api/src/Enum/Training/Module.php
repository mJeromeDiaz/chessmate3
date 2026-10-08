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
    /** Coordinates series: find the squares named on an empty board (docs/COORDINATES.md). */
    case Coordinates = 'coordinates';
    /** Blindfold puzzles: shown, hidden, solved from memory (docs/BLINDFOLD.md). */
    case Blindfold = 'blindfold';
    /** Position evaluation: who stands better, and the plan (docs/EVALUATION.md). */
    case Evaluation = 'evaluation';

    /** Its name for the user (emails, calendar). */
    public function label(): string
    {
        return match ($this) {
            self::Woodpecker => 'Woodpecker',
            self::Repertoire => 'Répertoire',
            self::Puzzles => 'Puzzles',
            self::Free => 'Libre',
            self::Coordinates => 'Coordonnées',
            self::Blindfold => 'Aveugle',
            self::Evaluation => 'Évaluation',
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
