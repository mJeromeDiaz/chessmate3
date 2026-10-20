<?php

declare(strict_types=1);

namespace App\Training\Run;

use App\Entity\Training\Run;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Another domain that judges a run when it closes, in the transaction that closes it (the mind
 * map's passes, docs/MIND_MAP.md): the player sees the verdict as soon as the run ends, no worker
 * needed. Autoconfigured: Training never depends on the domains that implement it.
 */
#[AutoconfigureTag(self::TAG)]
interface RunClosedListenerInterface
{
    public const TAG = 'app.training.run_closed_listener';

    /**
     * Called once, the run closed with its summary. Writes only (no business refusal: the closing
     * must go through).
     */
    public function onRunClosed(Run $run, \DateTimeImmutable $now): void;
}
