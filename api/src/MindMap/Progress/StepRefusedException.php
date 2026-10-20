<?php

declare(strict_types=1);

namespace App\MindMap\Progress;

/**
 * A step that cannot be played or skipped now (docs/MIND_MAP.md, § 3); $reason travels to the
 * client (409).
 */
final class StepRefusedException extends \RuntimeException
{
    /** Not reachable yet. */
    public const LOCKED = 'step_locked';
    /** Its module does not exist yet. */
    public const SOON = 'step_soon';
    /** A boss or a chest: never skipped; a step done: nothing to skip. */
    public const NOT_SKIPPABLE = 'step_not_skippable';

    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
