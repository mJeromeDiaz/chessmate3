<?php

declare(strict_types=1);

namespace App\Enum\Brilliancy;

/**
 * Where a position of « Brillant ou Blunder » stands (docs/BRILLIANCY.md): served, then judged.
 * Stored as its value: add cases, never rename one.
 */
enum AttemptStatus: string
{
    case Pending = 'pending';
    case Right = 'right';
    case Wrong = 'wrong';
}
