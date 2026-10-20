<?php

declare(strict_types=1);

namespace App\Notation\Drawing;

/**
 * An item drawn for the notation module: the position shown and the moves expected from it (UCI);
 * for a repertoire line, the moves played before, its key (its last segment), its opening's name and
 * the repertoire's colour.
 */
final readonly class Exercise
{
    /**
     * @param list<string> $expected
     * @param list<string> $shown    a repertoire line's moves played before (UCI, from the initial position)
     */
    public function __construct(
        public ?int $puzzleId,
        public string $fen,
        public array $expected,
        public array $shown = [],
        public ?string $lineKey = null,
        public ?string $label = null,
        public ?string $orientation = null,
    ) {
    }
}
