<?php

declare(strict_types=1);

namespace App\Chess\Pgn;

/**
 * Numeric Annotation Glyphs (PGN standard, section 10) and the move suffixes that stand for the
 * first six of them.
 */
final class Nag
{
    public const GOOD = 1;
    public const MISTAKE = 2;
    public const BRILLIANT = 3;
    public const BLUNDER = 4;
    public const INTERESTING = 5;
    public const DUBIOUS = 6;
    public const MAX = 255;

    public const SUFFIXES = [
        '!' => self::GOOD,
        '?' => self::MISTAKE,
        '!!' => self::BRILLIANT,
        '??' => self::BLUNDER,
        '!?' => self::INTERESTING,
        '?!' => self::DUBIOUS,
    ];

    public static function fromSuffix(string $suffix): ?int
    {
        return self::SUFFIXES[$suffix] ?? null;
    }

    /** The suffix of a move annotation (1 to 6), null for any other NAG. */
    public static function suffix(int $nag): ?string
    {
        $suffix = array_search($nag, self::SUFFIXES, true);

        return false === $suffix ? null : $suffix;
    }
}
