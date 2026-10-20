<?php

declare(strict_types=1);

namespace App\Enum\MindMap;

/**
 * The verdict on a run played for a step (docs/MIND_MAP.md, § 4). Stored as its value: add cases,
 * never rename one.
 */
enum AttemptOutcome: string
{
    /** The run is still going. */
    case Pending = 'pending';
    case Passed = 'passed';
    case Failed = 'failed';
}
