<?php

declare(strict_types=1);

namespace App\Enum\Repertoire;

/**
 * How a card was recalled (FSRS). Values of py-fsrs. In a repertoire test they come from the
 * answer and its think time ({@see \App\Repertoire\Srs\Grader}).
 */
enum Rating: int
{
    case Again = 1;
    case Hard = 2;
    case Good = 3;
    case Easy = 4;
}
