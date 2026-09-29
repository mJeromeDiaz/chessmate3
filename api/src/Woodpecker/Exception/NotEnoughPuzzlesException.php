<?php

declare(strict_types=1);

namespace App\Woodpecker\Exception;

/**
 * The criteria do not match enough selectable puzzles for the requested size.
 */
final class NotEnoughPuzzlesException extends \RuntimeException
{
    public function __construct(public readonly int $found, public readonly int $requested)
    {
        parent::__construct(sprintf('Only %d puzzles match (%d requested).', $found, $requested));
    }
}
