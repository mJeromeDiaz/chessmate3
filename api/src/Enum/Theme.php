<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * The colour theme the user chose for the SPA. Stored as its value: add cases, never rename one.
 */
enum Theme: string
{
    /** Follows the operating system's setting. */
    case Auto = 'auto';
    case Light = 'light';
    case Dark = 'dark';
}
