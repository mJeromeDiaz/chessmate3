<?php

declare(strict_types=1);

namespace App\Woodpecker\Set;

/**
 * Where a set draws its puzzles from: theme ids (OR; none: any theme), or an opening.
 */
final readonly class Pool
{
    /**
     * @param list<int> $themeIds puzzle_theme ids
     */
    private function __construct(
        public array $themeIds,
        public ?OpeningPool $opening,
    ) {
    }

    /**
     * @param list<int> $themeIds
     */
    public static function themes(array $themeIds): self
    {
        return new self($themeIds, null);
    }

    public static function opening(OpeningPool $opening): self
    {
        return new self([], $opening);
    }
}
