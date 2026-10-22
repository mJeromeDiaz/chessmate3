<?php

declare(strict_types=1);

namespace App\Endgame\Lesson;

use App\Enum\Endgame\Family;
use App\Enum\Endgame\Goal;
use App\Enum\Endgame\Result;

/**
 * One lesson of the endgames module (docs/ENDGAMES.md, § 3): its textbook position, the player
 * being the side to move, and how its variants are drawn ({@see \App\Endgame\Drawing\VariantDrawer}).
 */
final readonly class Lesson
{
    /**
     * @param list<int>   $fileShifts whole-board shifts along the ranks tried for variants (0 excluded)
     * @param string|null $material   pieces placed at random for more variants ("KQ-K": white king and
     *                                queen against the black king), checked by the tablebase
     */
    public function __construct(
        public string $key,
        public Family $family,
        public Goal $goal,
        public string $textbook,
        /** For the player, with perfect play: Win or Draw. */
        public Result $result,
        /** Hold: moves to keep the draw. */
        public int $holdMoves = 0,
        public array $fileShifts = [],
        /** Pawns must stay off the a- and h-files when shifted (a rook pawn changes the theory). */
        public bool $pawnsOffEdge = true,
        public ?string $material = null,
    ) {
    }
}
