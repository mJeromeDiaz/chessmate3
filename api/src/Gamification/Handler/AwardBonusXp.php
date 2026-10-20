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
use App\MindMap\Event\StepCompleted;
use App\PuzzleStreak\Event\RecordBeaten;
use App\Training\Event\SessionClosed;
use App\Woodpecker\Event\CycleCompleted;
use App\Woodpecker\Event\SetCompleted;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

/**
 * The bonuses (docs/GAMIFICATION.md): a session completed to its end (a parcours with none of its
 * tiers skipped, docs/PARCOURS.md), a Woodpecker cycle
 * completed in time, a Woodpecker set completed, an orientation of the coordinates validated for
 * the first time, a puzzle streak record beaten, a blindfold puzzle pardoned after its miss, a step
 * of a mind map path completed. Idempotent on their source (the cycle by set, number and run, the
 * validation by user and orientation, the record by streak, the pardon by attempt, the step by its
 * progress row, as
 * {@see \App\Gamification\Xp\XpRebuilder} keys them).
 */
final class AwardBonusXp
{
    public const VALIDATION_SOURCE = 'coordinates_validation';
    public const RECORD_SOURCE = 'puzzle_streak';
    public const PARDON_SOURCE = 'blindfold_pardon';
    public const MIND_MAP_SOURCE = 'mind_map_step';

    public function __construct(
        private readonly XpLedger $ledger,
        private readonly Connection $connection,
    ) {
    }

    #[AsMessageHandler]
    public function onSessionClosed(SessionClosed $event): void
    {
        if (SessionStatus::Completed->value !== $event->status) {
            return;
        }
        // A parcours' bonus depends on its effort (docs/PARCOURS.md).
        $effort = $this->connection->fetchOne(
            "SELECT JSON_UNQUOTE(JSON_EXTRACT(parcours, '$.effort')) FROM training_session WHERE id = ?",
            [Uuid::fromString($event->sessionId)->toBinary()],
            [ParameterType::BINARY],
        );
        // A parcours earns it played to its end only: none of its tiers skipped.
        if (\is_string($effort) && $event->skippedCount > 0) {
            return;
        }
        $xp = XpRules::sessionBonus(\is_string($effort) ? $effort : null);
        $this->ledger->awardBonus($event->userId, XpKind::Session, null, $xp, 'training_session', $event->sessionId, $event->occurredAt);
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

    #[AsMessageHandler]
    public function onStepCompleted(StepCompleted $event): void
    {
        if ($event->xp <= 0) {
            return;
        }
        $this->ledger->awardBonus(
            $event->userId,
            XpKind::MindMap,
            null,
            $event->xp,
            self::MIND_MAP_SOURCE,
            $event->progressId,
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
