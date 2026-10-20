<?php

declare(strict_types=1);

namespace App\MindMap\Path;

/**
 * The rules shared by every mind map path (docs/MIND_MAP.md, § 2).
 */
final class PathRules
{
    /** An exercise's lesson passes at this success rate (the result screen's « almost »). */
    public const PASS_RATE = 0.8;
    /** A boss is lost at its third failure. */
    public const BOSS_LIVES = 3;
    /** The XP of an exercise validated (its last pass), on top of the exercises' own XP. */
    public const EXERCISE_XP = 15;
}
