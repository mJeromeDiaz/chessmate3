<?php

declare(strict_types=1);

namespace App\Training\Module;

/**
 * What the client reports for an item, never a result: the moves it tried (UCI) and the help it
 * used, and the think time it measured (the repertoire test). Board-based modules share this shape.
 */
final readonly class ItemSubmission
{
    /**
     * @param list<string> $moves
     */
    public function __construct(
        public string $itemId,
        public array $moves,
        public int $hintLevel,
        public bool $solutionShown,
        /** Think time measured by the client (animations excluded); the server only lets it lower its own. */
        public ?int $thinkMs = null,
    ) {
    }
}
