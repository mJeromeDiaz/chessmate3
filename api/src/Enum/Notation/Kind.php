<?php

declare(strict_types=1);

namespace App\Enum\Notation;

/**
 * The exercises of the notation module (docs/NOTATION.md). Stored as its value: add cases, never
 * rename one.
 */
enum Kind: string
{
    /** A move announced (« Nf3 »): play it on the board. */
    case Play = 'play';
    /** A sequence of moves announced: play it, both sides. */
    case Sequence = 'sequence';
    /** A puzzle: solve it in one's head and write its whole line, without playing it. */
    case Write = 'write';
    /** A line of one's repertoire played up to its last half-moves: write them. */
    case Line = 'line';
    /** A legendary game, 6 half-moves at a time: watch them played, then write them. */
    case GameWrite = 'game_write';
    /** A legendary game, 6 half-moves at a time: read them, then play them, both sides. */
    case GamePlay = 'game_play';

    /** Drawn from the legendary games. */
    public function isGame(): bool
    {
        return self::GameWrite === $this || self::GamePlay === $this;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $kind): string => $kind->value, self::cases());
    }
}
