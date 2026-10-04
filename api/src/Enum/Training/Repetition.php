<?php

declare(strict_types=1);

namespace App\Enum\Training;

/**
 * How a saved session comes back (docs/TRAINING.md). Stored as its value: add cases, never rename one.
 */
enum Repetition: string
{
    /** No schedule: launched from the list whenever the user wants. */
    case OnDemand = 'on_demand';
    /** At a time, on the checked days of the week. */
    case Daily = 'daily';
    /** At a time, on one day of the week. */
    case Weekly = 'weekly';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $r): string => $r->value, self::cases());
    }
}
