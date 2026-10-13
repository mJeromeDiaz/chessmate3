<?php

declare(strict_types=1);

namespace App\Training\Module;

use App\Entity\Training\Run;

/**
 * A module whose run may go into overtime (the puzzle streak, docs/PUZZLE_STREAK.md): when its
 * time is up while {@see self::canExtend()} holds, the run waits
 * {@see \App\Training\Run\TimeboxRunner::DECISION_SECONDS} for the player to extend it
 * (+{@see \App\Training\Run\TimeboxRunner::EXTENSION_SECONDS}, again at the end of each extension)
 * or to end it; without an answer, it closes at its expiry (docs/TRAINING.md, "Prolongation").
 * Implemented by a {@see TimeboxedModuleInterface}.
 */
interface OvertimeModuleInterface
{
    /**
     * Whether the run's time being up interrupts something worth going on with (a streak alive).
     * Called under the run's lock, the run expired.
     */
    public function canExtend(Run $run): bool;
}
