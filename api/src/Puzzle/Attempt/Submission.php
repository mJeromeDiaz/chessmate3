<?php

declare(strict_types=1);

namespace App\Puzzle\Attempt;

/**
 * What the client reports: the moves it tried and the help it used. Never a result.
 */
final readonly class Submission
{
    /**
     * @param list<string> $moves UCI moves tried by the player, in order, wrong ones included
     */
    public function __construct(
        public array $moves,
        public int $hintLevel,
        public bool $solutionShown,
    ) {
    }
}
