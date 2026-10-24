<?php

declare(strict_types=1);

namespace App\Enum\Endgame;

/**
 * Where a position of the endgames module stands (docs/ENDGAMES.md, § 5). Stored as its value: add
 * cases, never rename one.
 */
enum AttemptStatus: string
{
    case Pending = 'pending';
    /** The goal reached: mate, promotion or conversion, the win kept. */
    case Won = 'won';
    /** The draw kept for the lesson's moves, or a dead draw reached. */
    case Held = 'held';
    /** A move of the player gave the result away. */
    case Spoiled = 'spoiled';
    /** The win kept, but not reached within the moves allowed. */
    case Overtime = 'overtime';
    /** Given up (« Passer »). */
    case Resigned = 'resigned';
    /** The Roi des cendres: the position's clock ran out (docs/ENDGAMES.md, § 10). */
    case Timeout = 'timeout';

    public function isSuccess(): bool
    {
        return self::Won === $this || self::Held === $this;
    }
}
