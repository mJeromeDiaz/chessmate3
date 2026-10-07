<?php

declare(strict_types=1);

namespace App\Training\Module;

/**
 * A module whose runs all last the same time (the coordinates series, docs/COORDINATES.md): its
 * start refuses any other budget, and a session step of it must last exactly that long.
 * Implemented by a {@see TimeboxedModuleInterface}.
 */
interface FixedBudgetInterface
{
    /** A whole number of minutes, in seconds. */
    public function fixedBudgetSeconds(): int;
}
