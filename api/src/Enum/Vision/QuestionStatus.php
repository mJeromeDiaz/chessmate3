<?php

declare(strict_types=1);

namespace App\Enum\Vision;

/**
 * Where a vision question stands (docs/VISION.md). Stored as its value: add cases, never rename one.
 */
enum QuestionStatus: string
{
    case Pending = 'pending';
    case Right = 'right';
    case Wrong = 'wrong';
    /** Answered after its time (`questionSeconds`), or not at all: a failure. */
    case Late = 'late';
}
