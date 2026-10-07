<?php

declare(strict_types=1);

namespace App\Coordinates\Series;

use Random\Randomizer;

/**
 * Draws the squares of a series (docs/COORDINATES.md): uniformly among the 64, never the same square
 * twice in a row (the answer would be a click on the square just found).
 */
final class SquareDrawer
{
    private readonly Randomizer $randomizer;

    public function __construct(?Randomizer $randomizer = null)
    {
        $this->randomizer = $randomizer ?? new Randomizer();
    }

    /**
     * @return list<string> squares such as "e4"
     */
    public function draw(int $count): array
    {
        $squares = [];
        $previous = -1;
        for ($i = 0; $i < $count; ++$i) {
            // 63 choices, the previous square skipped.
            $index = $this->randomizer->getInt(0, $previous < 0 ? 63 : 62);
            if ($previous >= 0 && $index >= $previous) {
                ++$index;
            }
            $squares[] = self::name($index);
            $previous = $index;
        }

        return $squares;
    }

    /** 0 = a1, 1 = b1... 63 = h8. */
    public static function name(int $index): string
    {
        return \chr(\ord('a') + $index % 8).(intdiv($index, 8) + 1);
    }

    public static function isSquare(string $square): bool
    {
        return 1 === preg_match('/^[a-h][1-8]$/', $square);
    }
}
