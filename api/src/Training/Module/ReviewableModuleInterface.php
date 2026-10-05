<?php

declare(strict_types=1);

namespace App\Training\Module;

use App\Entity\Training\Run;

/**
 * A module whose closed runs can be reviewed item by item (docs/TRAINING.md, run review): what was
 * played, its outcome, and what the client needs to replay it on its own (nothing is recorded by a
 * replay). Implemented by a {@see TimeboxedModuleInterface}; free study has no items.
 */
interface ReviewableModuleInterface
{
    /**
     * The items resolved in the run, in the order they were played. Items not counted (dropped,
     * interrupted) are left out.
     *
     * @return list<ReviewItem>
     */
    public function review(Run $run): array;
}
