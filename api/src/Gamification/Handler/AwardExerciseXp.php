<?php

declare(strict_types=1);

namespace App\Gamification\Handler;

use App\Activity\Event\ExerciseCompleted;
use App\Gamification\Xp\XpLedger;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * An exercise finished: its XP, within the daily cap (docs/GAMIFICATION.md). Idempotent.
 */
#[AsMessageHandler]
final class AwardExerciseXp
{
    public function __construct(private readonly XpLedger $ledger)
    {
    }

    public function __invoke(ExerciseCompleted $event): void
    {
        $this->ledger->awardExercise($event);
    }
}
