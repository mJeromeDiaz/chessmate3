<?php

declare(strict_types=1);

namespace App\Enum\Puzzle;

enum RatingChangeReason: string
{
    case Attempt = 'attempt';
    case LichessImport = 'lichess_import';
    /** The level declared in the guided tour (docs/ONBOARDING.md). */
    case LevelDeclared = 'level_declared';
}
