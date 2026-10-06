<?php

declare(strict_types=1);

namespace App\Puzzle\Attempt;

use App\Activity\Event\ExerciseCompleted;
use App\Entity\Catalog\Puzzle;
use App\Entity\Puzzle\Attempt;
use App\Enum\Activity\ExerciseType;
use App\Enum\Puzzle\AttemptStatus;

/**
 * The common event of a resolved Phase 2 attempt (also rebuilt by the activity backfill).
 */
final class AttemptEvents
{
    public const SOURCE_TYPE = 'puzzle_attempt';

    public static function completed(Attempt $attempt, Puzzle $puzzle): ExerciseCompleted
    {
        $submittedAt = $attempt->getSubmittedAt();
        if (null === $submittedAt) {
            throw new \LogicException('Only a resolved attempt is a completed exercise.');
        }

        $change = $attempt->getRatingChange();

        return new ExerciseCompleted(
            userId: $attempt->getUser()->getId()->toRfc4122(),
            type: $attempt->isRated() ? ExerciseType::PuzzleRated : ExerciseType::PuzzleUnrated,
            success: AttemptStatus::Solved === $attempt->getStatus(),
            durationMs: $attempt->getDurationMs() ?? 0,
            itemCount: 1,
            sourceType: self::SOURCE_TYPE,
            sourceId: $attempt->getId()->toRfc4122(),
            occurredAt: $submittedAt,
            metadata: [
                'puzzleId' => $puzzle->getLichessId(),
                'puzzleRating' => $puzzle->getRating(),
                'ratingDelta' => null === $change ? null : round($change->getRatingDelta(), 1),
                'mistakes' => $attempt->getMistakes(),
                'hintLevel' => $attempt->getHintLevel(),
                'solutionShown' => $attempt->isSolutionShown(),
                // Played in a timed run (docs/TRAINING.md).
                'trainingRunId' => $attempt->getTrainingRun()?->getId()->toRfc4122(),
            ],
        );
    }
}
