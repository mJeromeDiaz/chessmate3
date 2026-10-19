<?php

declare(strict_types=1);

namespace App\Blindfold\Recall;

/**
 * A puzzle's position for a recall level ({@see PuzzlePositionPicker}): $fen is shown (after the
 * opponent's first move), $expectedPlacement is to rebuild (after the $announced moves, if any).
 */
final readonly class PuzzlePosition
{
    /**
     * @param list<string> $announced UCI
     */
    public function __construct(
        public int $puzzleId,
        public string $fen,
        public string $expectedPlacement,
        public array $announced,
    ) {
    }
}
