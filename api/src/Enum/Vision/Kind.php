<?php

declare(strict_types=1);

namespace App\Enum\Vision;

/**
 * What a vision run asks (docs/VISION.md, § 2), its config `kind`. Stored as its value: add cases,
 * never rename one.
 */
enum Kind: string
{
    /** A generated position remembered: where is a piece, what stands on a square. */
    case Locate = 'locate';
    /** A puzzle's position remembered, 3 moves announced: a piece en prise. */
    case EnPrise = 'en_prise';
    /** A puzzle's position remembered, 3 moves announced: the pieces attacking a square. */
    case Attackers = 'attackers';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $kind): string => $kind->value, self::cases());
    }
}
