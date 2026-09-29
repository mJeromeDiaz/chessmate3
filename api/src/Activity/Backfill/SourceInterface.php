<?php

declare(strict_types=1);

namespace App\Activity\Backfill;

use App\Activity\Event\ExerciseCompleted;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Exercises completed before the activity log existed, per domain, for `app:activity:backfill`
 * (docs/ACTIVITY.md, "Adding an exercise type"). Autoconfigured: implementing it is enough.
 */
#[AutoconfigureTag(self::TAG)]
interface SourceInterface
{
    public const TAG = 'app.activity.backfill_source';

    public function getName(): string;

    /**
     * Completed exercises in a stable order, by batches (keyset pagination: memory stays flat).
     *
     * @return iterable<list<ExerciseCompleted>>
     */
    public function batches(int $batchSize): iterable;
}
