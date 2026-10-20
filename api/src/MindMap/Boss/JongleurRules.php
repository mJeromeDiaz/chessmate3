<?php

declare(strict_types=1);

namespace App\MindMap\Boss;

use App\MindMap\Path\PathRules;
use App\Training\Run\TimeboxRunner;

/**
 * The rules of the Jongleur (docs/MIND_MAP.md, § 8): quick questions mixing the chapter 1's
 * exercises, each in its time, and the boss's lives.
 */
final class JongleurRules
{
    public const BOSS = 'jongleur';
    /** The time of a question, whatever it asks. */
    public const QUESTION_SECONDS = 6;
    /** The fight is lost at this many failures (a late answer is one). */
    public const LIVES = PathRules::BOSS_LIVES;
    /** The longest fight (the chapter 1's boss). */
    public const MAX_COUNT = 20;

    /**
     * Whether an answer comes after its question's time. The start is stored to the second
     * (truncated): one more second, and the network's tolerance, always in the player's favour.
     */
    public static function isLate(\DateTimeImmutable $startedAt, \DateTimeImmutable $answeredAt): bool
    {
        $deadlineMs = ((int) $startedAt->format('U') + self::QUESTION_SECONDS + 1) * 1000 + TimeboxRunner::SUBMISSION_TOLERANCE_MS;

        return (int) round((float) $answeredAt->format('U.u') * 1000) > $deadlineMs;
    }
}
