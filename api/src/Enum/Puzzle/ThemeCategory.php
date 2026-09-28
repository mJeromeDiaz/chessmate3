<?php

declare(strict_types=1);

namespace App\Enum\Puzzle;

/**
 * Theme groups, in the order Lichess shows them on its theme page.
 */
enum ThemeCategory: string
{
    case Phases = 'phases';
    case Motifs = 'motifs';
    case Advanced = 'advanced';
    case Mates = 'mates';
    case MateThemes = 'mateThemes';
    case SpecialMoves = 'specialMoves';
    case Goals = 'goals';
    case Lengths = 'lengths';
    case Origin = 'origin';

    public function labelFr(): string
    {
        return match ($this) {
            self::Phases => 'Phases de jeu',
            self::Motifs => 'Motifs',
            self::Advanced => 'Avancé',
            self::Mates => 'Mats',
            self::MateThemes => 'Thèmes de mat',
            self::SpecialMoves => 'Coups spéciaux',
            self::Goals => 'Objectifs',
            self::Lengths => 'Longueur',
            self::Origin => 'Origine',
        };
    }

    public function labelEn(): string
    {
        return match ($this) {
            self::Phases => 'Phases',
            self::Motifs => 'Motifs',
            self::Advanced => 'Advanced',
            self::Mates => 'Mates',
            self::MateThemes => 'Mate themes',
            self::SpecialMoves => 'Special moves',
            self::Goals => 'Goals',
            self::Lengths => 'Lengths',
            self::Origin => 'Origin',
        };
    }
}
