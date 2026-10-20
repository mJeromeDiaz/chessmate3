<?php

declare(strict_types=1);

namespace App\Enum\MindMap;

/**
 * Where a question of a boss stands (docs/MIND_MAP.md, § 8). Stored as its value: add cases, never
 * rename one.
 */
enum BossItemStatus: string
{
    case Pending = 'pending';
    case Right = 'right';
    case Wrong = 'wrong';
    /** Answered after its time (or not at all): a failure. */
    case Late = 'late';
}
