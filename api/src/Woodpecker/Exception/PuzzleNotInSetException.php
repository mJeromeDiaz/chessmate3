<?php

declare(strict_types=1);

namespace App\Woodpecker\Exception;

/**
 * The puzzle is not (or no longer) in the set's list.
 */
final class PuzzleNotInSetException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Puzzle not in this set.');
    }
}
