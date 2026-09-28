<?php

declare(strict_types=1);

namespace App\Puzzle\Selection;

use App\Enum\Puzzle\Difficulty;

final readonly class SelectionCriteria
{
    /**
     * @param list<int> $themeIds puzzle_theme ids; a puzzle matches if it has at least one (OR)
     */
    public function __construct(
        public array $themeIds = [],
        public Difficulty $difficulty = Difficulty::Normal,
    ) {
    }
}
