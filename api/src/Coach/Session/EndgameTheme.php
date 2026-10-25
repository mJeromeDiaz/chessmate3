<?php

declare(strict_types=1);

namespace App\Coach\Session;

use App\Enum\Chess\EndgameType;
use App\Enum\Endgame\Family;
use Random\Randomizer;

/**
 * The endgames theme of the tailored session (docs/COACH.md, § 3): the user's weakest endgame in
 * their games, as the endgames module names its themes; without one (or a mixed one), a theme
 * drawn at random.
 */
final class EndgameTheme
{
    private readonly Randomizer $randomizer;

    public function __construct(?Randomizer $randomizer = null)
    {
        $this->randomizer = $randomizer ?? new Randomizer();
    }

    /**
     * @param string|null $weakest the weakest endgame type (EndgameType value), null without one
     */
    public function for(?string $weakest): Family
    {
        $candidates = match (null === $weakest ? null : EndgameType::tryFrom($weakest)) {
            EndgameType::Pawn => [Family::Pawns],
            EndgameType::Rook, EndgameType::RookMinor => [Family::Rooks],
            EndgameType::Queen => [Family::Queens],
            EndgameType::Minor => [Family::Bishops, Family::Knights],
            default => Family::cases(),
        };

        return $candidates[$this->randomizer->getInt(0, \count($candidates) - 1)];
    }
}
