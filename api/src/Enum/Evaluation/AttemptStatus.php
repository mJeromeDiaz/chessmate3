<?php

declare(strict_types=1);

namespace App\Enum\Evaluation;

/**
 * Where an evaluation stands (docs/EVALUATION.md): served, then judged against the engine's
 * category. Stored as its value: add cases, never rename one.
 */
enum AttemptStatus: string
{
    case Pending = 'pending';
    /** The engine's category. */
    case Exact = 'exact';
    /** One category away. */
    case Close = 'close';
    /** Two or more away. */
    case Miss = 'miss';
    /** No answer within the position's time. */
    case Timeout = 'timeout';
}
