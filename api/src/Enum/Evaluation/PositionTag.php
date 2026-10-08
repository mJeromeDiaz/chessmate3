<?php

declare(strict_types=1);

namespace App\Enum\Evaluation;

/**
 * The kind of a position, shown on the board (docs/EVALUATION.md). Stored as its value: add cases,
 * never rename one.
 */
enum PositionTag: string
{
    case Opening = 'opening';
    case Middlegame = 'middlegame';
    case Structure = 'structure';
    case Endgame = 'endgame';

    public function label(): string
    {
        return match ($this) {
            self::Opening => 'Ouverture',
            self::Middlegame => 'Milieu de partie',
            self::Structure => 'Structure',
            self::Endgame => 'Finale',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $tag): string => $tag->value, self::cases());
    }
}
