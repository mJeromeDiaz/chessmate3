<?php

declare(strict_types=1);

namespace App\Enum\MindMap;

/**
 * What a question of the Jongleur asks (docs/MIND_MAP.md, § 8). Stored as its value: add cases,
 * never rename one.
 */
enum BossQuestion: string
{
    /** A square's name shown: click it on the board. */
    case Find = 'find';
    /** A square lit on the board: give its name. */
    case Name = 'name';
    /** A square's name shown, no board: light or dark? */
    case Color = 'color';
    /** A move announced in a puzzle's position: play it. */
    case Play = 'play';
    /** A move shown played in a puzzle's position: write it (SAN). */
    case Write = 'write';

    /** Asked about a square (the coordinates' modes), not in a position. */
    public function isSquare(): bool
    {
        return \in_array($this, [self::Find, self::Name, self::Color], true);
    }
}
