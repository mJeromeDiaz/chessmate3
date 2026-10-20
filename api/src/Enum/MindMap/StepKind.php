<?php

declare(strict_types=1);

namespace App\Enum\MindMap;

/**
 * What a step of a mind map path is (docs/MIND_MAP.md).
 */
enum StepKind: string
{
    /** Played 2 to 4 times (its passes), each a short lesson at 80 % at least; may be skipped. */
    case Exercise = 'exercise';
    /** A chest on the way: earned once every exercise before it in the chapter is passed. */
    case Chest = 'chest';
    /** Closes its section: 3 lives, never skipped; beaten, it opens the next section. */
    case MiniBoss = 'mini_boss';
    /** Revealed when its section is cleared: optional, 3 lives, a bonus. */
    case HiddenBoss = 'hidden_boss';
    /** Closes its chapter: 3 lives, never skipped; beaten, it opens the next chapter. */
    case Boss = 'boss';

    /** A boss of any size: one pass, judged by its lives. */
    public function isBoss(): bool
    {
        return self::Exercise !== $this && self::Chest !== $this;
    }
}
