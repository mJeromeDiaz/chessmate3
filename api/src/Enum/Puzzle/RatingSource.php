<?php

declare(strict_types=1);

namespace App\Enum\Puzzle;

/**
 * Where a puzzle rating started from.
 */
enum RatingSource: string
{
    case Default = 'default';
    case Lichess = 'lichess';
}
