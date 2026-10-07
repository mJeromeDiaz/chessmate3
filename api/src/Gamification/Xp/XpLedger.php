<?php

declare(strict_types=1);

namespace App\Gamification\Xp;

use App\Activity\Event\ExerciseCompleted;
use App\Activity\Log\LocalDate;
use App\Entity\User;
use App\Enum\Gamification\XpKind;
use App\Repository\UserRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Uid\Uuid;

/**
 * Writes the XP gains (docs/GAMIFICATION.md), one row per source, in plain SQL: `INSERT … ON
 * DUPLICATE KEY UPDATE id = id` on the unique source, so a redelivered event gains nothing more.
 * An exercise gains what is left of the daily cap of its local day (two events handled at the
 * very same moment may slightly pass it: accepted).
 */
final readonly class XpLedger
{
    public function __construct(
        private Connection $connection,
        private UserRepository $users,
    ) {
    }

    /**
     * @return int XP gained (0: already counted, cap reached, or unknown user)
     */
    public function awardExercise(ExerciseCompleted $event): int
    {
        $user = $this->user($event->userId);
        if (null === $user) {
            return 0;
        }
        $localDate = LocalDate::of($event->occurredAt, $user->getDateTimeZone())->format('Y-m-d');
        $runId = $event->metadata['trainingRunId'] ?? null;

        return $this->insert(
            $user,
            XpKind::Exercise,
            XpRules::module($event->type)->value,
            $this->capped($user, $event, $localDate, 0),
            $event->sourceType,
            $event->sourceId,
            \is_string($runId) && Uuid::isValid($runId) ? Uuid::fromString($runId) : null,
            $localDate,
            $event->occurredAt,
        );
    }

    /**
     * What awardExercise() will gain for this exercise, written or not yet: its rule, within what is
     * left of the daily cap once $pending more XP (exercises of the same request, not written yet)
     * are counted. Writes nothing.
     */
    public function preview(ExerciseCompleted $event, int $pending = 0): int
    {
        $user = $this->user($event->userId);
        if (null === $user) {
            return 0;
        }

        return $this->capped($user, $event, LocalDate::of($event->occurredAt, $user->getDateTimeZone())->format('Y-m-d'), $pending);
    }

    /**
     * A bonus (session, cycle, set, quest, validation): not capped. $runId: the run where it was
     * earned, when it belongs to the run's XP (a validation).
     *
     * @return int XP gained (0: already counted, or unknown user)
     */
    public function awardBonus(string $userId, XpKind $kind, ?string $module, int $xp, string $sourceType, string $sourceId, \DateTimeImmutable $occurredAt, ?Uuid $runId = null): int
    {
        $user = $this->user($userId);
        if (null === $user) {
            return 0;
        }

        return $this->insert(
            $user,
            $kind,
            $module,
            $xp,
            $sourceType,
            $sourceId,
            $runId,
            LocalDate::of($occurredAt, $user->getDateTimeZone())->format('Y-m-d'),
            $occurredAt,
        );
    }

    private function capped(User $user, ExerciseCompleted $event, string $localDate, int $pending): int
    {
        $earned = XpRules::exercise($event->type, $event->success, $event->durationMs, $event->itemCount, $event->metadata);

        return max(0, min($earned, XpRules::DAILY_EXERCISE_CAP - $this->exerciseXpOn($user, $localDate) - $pending));
    }

    private function exerciseXpOn(User $user, string $localDate): int
    {
        $sum = $this->connection->fetchOne(
            'SELECT COALESCE(SUM(xp), 0) FROM gamification_xp_entry WHERE user_id = ? AND local_date = ? AND kind = ?',
            [$user->getId()->toBinary(), $localDate, XpKind::Exercise->value],
            [ParameterType::BINARY],
        );

        return is_numeric($sum) ? (int) $sum : 0;
    }

    private function insert(User $user, XpKind $kind, ?string $module, int $xp, string $sourceType, string $sourceId, ?Uuid $runId, string $localDate, \DateTimeImmutable $occurredAt): int
    {
        $affected = $this->connection->executeStatement(
            'INSERT INTO gamification_xp_entry (id, user_id, kind, module, xp, source_type, source_id, training_run_id, local_date, occurred_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE id = id',
            [
                Uuid::v7()->toBinary(), $user->getId()->toBinary(), $kind->value, $module, max(0, $xp), $sourceType, $sourceId,
                $runId?->toBinary(), $localDate, $occurredAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            ],
            [
                ParameterType::BINARY, ParameterType::BINARY, ParameterType::STRING, ParameterType::STRING, ParameterType::INTEGER,
                ParameterType::STRING, ParameterType::STRING, ParameterType::BINARY,
            ],
        );

        return 1 === $affected ? max(0, $xp) : 0;
    }

    private function user(string $userId): ?User
    {
        return Uuid::isValid($userId) ? $this->users->find(Uuid::fromString($userId)) : null;
    }
}
