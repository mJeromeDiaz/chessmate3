<?php

declare(strict_types=1);

namespace App\Gamification\Handler;

use App\Blindfold\Event\PuzzlePardoned;
use App\Coordinates\Event\SeriesValidated;
use App\Enum\Gamification\XpKind;
use App\Enum\Training\Module;
use App\Enum\Training\SessionStatus;
use App\Gamification\Xp\XpLedger;
use App\Gamification\Xp\XpRules;
use App\PuzzleStreak\Event\RecordBeaten;
use App\Training\Event\SessionClosed;
use App\Woodpecker\Event\CycleCompleted;
use App\Woodpecker\Event\SetCompleted;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

/**
 * The bonuses (docs/GAMIFICATION.md): a session completed to its end, a Woodpecker cycle
 * completed in time, a Woodpecker set completed, an orientation of the coordinates validated for
 * the first time, a puzzle streak record beaten, a blindfold puzzle pardoned after its miss.
 * Idempotent on their source (the cycle by set, number and run, the validation by user and
 * orientation, the record by streak, the pardon by attempt, as
 * {@see \App\Gamification\Xp\XpRebuilder} keys them).
 */
final class AwardBonusXp
{
    public const VALIDATION_SOURCE = 'coordinates_validation';
    public const RECORD_SOURCE = 'puzzle_streak';
    public const PARDON_SOURCE = 'blindfold_pardon';

    public function __construct(private readonly XpLedger $ledger)
    {
    }

    #[AsMessageHandler]
    public function onSessionClosed(SessionClosed $event): void
    {
        if (SessionStatus::Completed->value !== $event->status) {
            return;
        }
        $this->ledger->awardBonus($event->userId, XpKind::Session, null, XpRules::SESSION_COMPLETED, 'training_session', $event->sessionId, $event->occurredAt);
    }

    #[AsMessageHandler]
    public function onCycleCompleted(CycleCompleted $event): void
    {
        $this->ledger->awardBonus(
            $event->userId,
            XpKind::Cycle,
            Module::Woodpecker->value,
            XpRules::CYCLE_COMPLETED,
            'woodpecker_cycle',
            self::cycleSource($event->setId, $event->cycleNumber, $event->run),
            $event->occurredAt,
        );
    }

    #[AsMessageHandler]
    public function onSetCompleted(SetCompleted $event): void
    {
        $this->ledger->awardBonus($event->userId, XpKind::Set, Module::Woodpecker->value, XpRules::SET_COMPLETED, 'woodpecker_set', $event->setId, $event->occurredAt);
    }

    #[AsMessageHandler]
    public function onSeriesValidated(SeriesValidated $event): void
    {
        $this->ledger->awardBonus(
            $event->userId,
            XpKind::Validation,
            Module::Coordinates->value,
            XpRules::COORDINATES_VALIDATED,
            self::VALIDATION_SOURCE,
            self::validationSource($event->userId, $event->orientation),
            $event->occurredAt,
            Uuid::isValid($event->runId) ? Uuid::fromString($event->runId) : null,
        );
    }

    #[AsMessageHandler]
    public function onRecordBeaten(RecordBeaten $event): void
    {
        $this->ledger->awardBonus(
            $event->userId,
            XpKind::Record,
            Module::PuzzleStreak->value,
            XpRules::STREAK_RECORD,
            self::RECORD_SOURCE,
            $event->streakId,
            $event->occurredAt,
            Uuid::isValid($event->runId) ? Uuid::fromString($event->runId) : null,
        );
    }

    #[AsMessageHandler]
    public function onPuzzlePardoned(PuzzlePardoned $event): void
    {
        $this->ledger->awardBonus(
            $event->userId,
            XpKind::Pardon,
            Module::Blindfold->value,
            XpRules::BLINDFOLD_PARDON,
            self::PARDON_SOURCE,
            $event->attemptId,
            $event->occurredAt,
            Uuid::isValid($event->runId) ? Uuid::fromString($event->runId) : null,
        );
    }

    /** A validation's source: one gain per user and orientation, whatever the series. */
    public static function validationSource(string $userId, string $orientation): string
    {
        return \sprintf('%s:%s', $userId, $orientation);
    }

    /** A cycle's source: one gain per set, cycle number and run (a lost cycle is played again). */
    public static function cycleSource(string $setId, int $cycleNumber, int $run): string
    {
        return \sprintf('%s:%d:%d', $setId, $cycleNumber, $run);
    }
}
