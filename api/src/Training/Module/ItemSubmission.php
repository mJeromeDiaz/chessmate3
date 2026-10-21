<?php

declare(strict_types=1);

namespace App\Training\Module;

/**
 * What the client reports for an item, never a result: the moves it tried (UCI) and the help it
 * used, and the think time it measured (the repertoire test). One exception: chapter 3 of the
 * calculation path is judged by the engine in the browser, which sends its verdict (`engine`,
 * docs/ENGINE.md, § 3). Board-based modules share this shape;
 * the coordinates series sends its answers instead (docs/COORDINATES.md), the position evaluation
 * its evaluation (docs/EVALUATION.md).
 */
final readonly class ItemSubmission
{
    /**
     * @param list<string>                                     $moves
     * @param list<array{index: int, square: string, ms: int}> $answers    squares clicked (coordinates series)
     * @param array{guess?: int|null, plan?: string|null}|null  $evaluation the category chosen (-2 to 2, null: none in time) and the plan (position evaluation)
 * @param list<array{square: string, piece: string}>       $placement  pieces placed (position recall)
     * @param list<string>                                     $written    half-moves written (notation)
     * @param list<string>                                     $squares    squares clicked (vision)
     * @param list<list<string>>                               $lines      lines played, UCI (calculation 3.2: one per reply)
     * @param array{right: bool, mistakeAt?: int|null}|null    $engine     the engine's verdict (calculation, chapter 3)
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
        /** The puzzle streak: skipped with a pass of the store, before any move (docs/PUZZLE_STREAK.md). */
        public bool $pass = false,
        /** Position recall: the pieces placed, FEN piece letters (docs/BLINDFOLD_RECALL.md). */
        public array $placement = [],
        /** Notation: the half-moves written, SAN with English letters (docs/NOTATION.md). */
        public array $written = [],
        /** Vision: the squares clicked on the empty board (docs/VISION.md). */
        public array $squares = [],
        /** Vision: a choice among the question's (a number, yes or no). */
        public ?string $choice = null,
        public array $lines = [],
        public ?array $engine = null,
    ) {
    }
}
