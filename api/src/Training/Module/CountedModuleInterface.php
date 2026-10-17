<?php

declare(strict_types=1);

namespace App\Training\Module;

/**
 * A module whose run may end by a count of exercises rather than by its time (a parcours tier,
 * docs/PARCOURS.md): such a run may then last up to {@see \App\Training\Run\TimeboxRunner::MAX_COUNTED_BUDGET_SECONDS}
 * (until its parcours' midnight), the clock being only its expiry. Implemented by a
 * {@see TimeboxedModuleInterface}.
 */
interface CountedModuleInterface
{
    /**
     * @param array<string, mixed> $config the run's
     */
    public function isCounted(array $config): bool;
}
