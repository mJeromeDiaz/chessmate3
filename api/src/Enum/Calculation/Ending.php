<?php

declare(strict_types=1);

namespace App\Enum\Calculation;

/**
 * How a line of the calculation module ends (docs/CALCULATION.md, § 2). Stored as its value: add
 * cases, never rename one.
 */
enum Ending: string
{
    case Mate = 'mate';
    /** No mate: the solution wins material (or another gain), played exactly. */
    case Material = 'material';
}
