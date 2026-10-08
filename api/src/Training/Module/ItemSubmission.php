<?php

declare(strict_types=1);

namespace App\Training\Module;

/**
 * What the client reports for an item, never a result: the moves it tried (UCI) and the help it
 * used, and the think time it measured (the repertoire test). Board-based modules share this shape;
 * the coordinates series sends its answers instead (docs/COORDINATES.md), the position evaluation
 * its evaluation (docs/EVALUATION.md).
 */
final readonly class ItemSubmission
{
    /**
     * @param list<string>                                     $moves
     * @param list<array{index: int, square: string, ms: int}> $answers    squares clicked (coordinates series)
     * @param array{guess?: int|null, plan?: string|null}|null  $evaluation the category chosen (-2 to 2, null: none in time) and the plan (position evaluation)
     */
    public function __construct(
        public string $itemId,
        public array $moves,
        public int $hintLevel,
        public bool $solutionShown,
        /** Think time measured by the client (animations excluded); the server only lets it lower its own. */
        public ?int $thinkMs = null,
        public array $answers = [],
        public ?array $evaluation = null,
    ) {
    }
}
