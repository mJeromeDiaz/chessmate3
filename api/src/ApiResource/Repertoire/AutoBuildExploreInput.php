<?php

declare(strict_types=1);

namespace App\ApiResource\Repertoire;

/**
 * POST /repertoires/auto-builds/{id}/explore: another round of the exploration. `resume` after the
 * cap allows another round of calls; `family` and `cut` (a line's) explore that line only, with its
 * own calls ("Explorer la suite").
 */
final class AutoBuildExploreInput
{
    public bool $resume = false;
    public ?string $family = null;
    public ?string $cut = null;
}
