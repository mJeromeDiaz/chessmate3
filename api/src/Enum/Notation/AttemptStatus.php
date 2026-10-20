<?php

declare(strict_types=1);

namespace App\Enum\Notation;

/**
 * Where an item of the notation module stands (docs/NOTATION.md). Stored as its value: add cases,
 * never rename one.
 */
enum AttemptStatus: string
{
    case Pending = 'pending';
    case Right = 'right';
    case Wrong = 'wrong';
}
