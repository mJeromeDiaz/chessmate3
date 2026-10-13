<?php

declare(strict_types=1);

namespace App\Training\Module;

/**
 * A module whose runs last one of a few lengths only (the coordinates series, docs/COORDINATES.md;
 * the position evaluation, docs/EVALUATION.md):
 * its start refuses any other budget, and a session step of it must last one of them.
 * Implemented by a {@see TimeboxedModuleInterface}.
 */
interface BudgetChoicesInterface
{
    /**
     * @return list<int> whole numbers of minutes, in seconds
     */
    public function allowedBudgetSeconds(): array;
}
