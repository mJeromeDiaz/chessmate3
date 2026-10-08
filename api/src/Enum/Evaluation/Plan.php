<?php

declare(strict_types=1);

namespace App\Enum\Evaluation;

/**
 * The plan of a position (docs/EVALUATION.md): the five the player may pick. Stored as its value:
 * add cases, never rename one.
 */
enum Plan: string
{
    case KingAttack = 'king_attack';
    case Queenside = 'queenside';
    case CenterSpace = 'center_space';
    case Simplify = 'simplify';
    case ActiveDefense = 'active_defense';

    public function label(): string
    {
        return match ($this) {
            self::KingAttack => 'Attaque sur le roi',
            self::Queenside => 'Jeu à l’aile dame',
            self::CenterSpace => 'Centre et espace',
            self::Simplify => 'Simplifier en finale',
            self::ActiveDefense => 'Défense active',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $plan): string => $plan->value, self::cases());
    }
}
