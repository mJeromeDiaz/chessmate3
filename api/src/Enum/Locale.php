<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * The language the user chose for the SPA (docs/I18N.md), later for the emails too. Stored as its
 * value: add cases, never rename one.
 */
enum Locale: string
{
    case French = 'fr';
    case English = 'en';
}
