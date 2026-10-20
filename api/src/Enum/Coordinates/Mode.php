<?php

declare(strict_types=1);

namespace App\Enum\Coordinates;

/**
 * What a coordinates series asks (docs/COORDINATES.md, § 2 bis). Stored as its value: add cases,
 * never rename one.
 */
enum Mode: string
{
    /** A square's name shown: click it on the board. */
    case Find = 'find';
    /** A square lit on the board: give its name (a file, a rank). */
    case Name = 'name';
    /** A square's name shown, no board: light or dark? */
    case Color = 'color';

    public const LIGHT = 'light';
    public const DARK = 'dark';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $mode): string => $mode->value, self::cases());
    }

    /**
     * The right answer about a square: the square itself, or its colour.
     */
    public function expected(string $square): string
    {
        return self::Color === $this ? self::colorOf($square) : $square;
    }

    /**
     * Whether an answer has the form this mode takes (a square, or light / dark).
     */
    public function accepts(string $answer): bool
    {
        return self::Color === $this
            ? \in_array($answer, [self::LIGHT, self::DARK], true)
            : 1 === preg_match('/^[a-h][1-8]$/', $answer);
    }

    /** a1 is dark, h1 light. */
    public static function colorOf(string $square): string
    {
        $file = \ord($square[0]) - \ord('a');
        $rank = (int) $square[1];

        return 1 === ($file + $rank) % 2 ? self::DARK : self::LIGHT;
    }
}
