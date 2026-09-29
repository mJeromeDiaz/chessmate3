<?php

declare(strict_types=1);

namespace App\Training\Module;

/**
 * What the client reports for an item, never a result: the moves it tried (UCI) and the help it
 * used. Board-based modules share this shape; a future module may add fields.
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
    ) {
    }
}
