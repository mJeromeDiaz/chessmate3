<?php

declare(strict_types=1);

namespace App\Calculation\Judge;

/**
 * A line with a half-move that cannot be played (illegal, or after the mate): the board lets none
 * through, so the client is not honest.
 */
final class IllegalLineException extends \RuntimeException
{
    public function __construct(public readonly int $index)
    {
        parent::__construct(\sprintf('Half-move %d cannot be played.', $index + 1));
    }
}
