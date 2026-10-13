<?php

declare(strict_types=1);

namespace App\Woodpecker\Set;

use App\Enum\Repertoire\Color;

/**
 * The puzzles of an opening set (the tailored session, docs/COACH.md): those of an opening family,
 * where the player plays the user's colour in it, or either side when that colour has too few.
 */
final readonly class OpeningPool
{
    /**
     * @param string $family     the family's catalogue tag ("Italian_Game")
     * @param Color  $color      the colour the user plays the opening with (one opening set per colour)
     * @param bool   $bothColors puzzles of both sides, not only the user's colour
     */
    public function __construct(
        public string $family,
        public Color $color,
        public bool $bothColors = false,
    ) {
    }

    /** "w", "b", or null (both sides). */
    public function solverColor(): ?string
    {
        return $this->bothColors ? null : $this->color->turn();
    }
}
