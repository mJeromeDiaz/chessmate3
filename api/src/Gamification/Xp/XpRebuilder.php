<?php

declare(strict_types=1);

namespace App\Gamification\Xp;

use App\Activity\Log\LocalDate;
use App\Entity\User;
use App\Enum\Activity\ExerciseType;
use App\Enum\Blindfold\Pardon;
use App\Enum\Gamification\XpKind;
use App\Enum\MindMap\Capacity;
use App\Enum\Training\Module;
use App\Enum\Training\SessionStatus;
use App\Enum\Woodpecker\CycleStatus;
use App\Enum\Woodpecker\SetStatus;
use App\Gamification\Handler\AwardBonusXp;
use App\MindMap\Path\Paths;
use App\Repository\UserRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Uid\Uuid;

/**
 * Recomputes the XP of a user from what they played (docs/GAMIFICATION.md): the activity log in
 * chronological order (the daily cap applied on each `local_date` written with the entry), the
 * completed sessions, Woodpecker cycles and sets, the first validation of each orientation of
 * the coordinates, the puzzle streak records, the blindfold puzzles pardoned after their miss and
 * the mind map steps completed (their XP as the path gives it today). Quest rewards are kept. One transaction per
 * user; the same rows as the handlers would have written, under the same sources.
 */
final readonly class XpRebuilder
{
    private const BATCH = 500;

    public function __construct(
        private Connection $connection,
        private UserRepository $users,
    ) {
    }

    /**
     * @return int XP gained
     */
    public function rebuild(User $user): int
    {
        return $this->connection->transactional(function () use ($user): int {
            $id = $user->getId()->toBinary();
            $this->connection->executeStatement(
                'DELETE FROM gamification_xp_entry WHERE user_id = ? AND kind <> ?',
                [$id, XpKind::Quest->value],
                [ParameterType::BINARY],
            );

            return $this->exercises($user) + $this->bonuses($user);
        });
    }

    /**
     * @return iterable<User>
     */
    public function users(): iterable
    {
        foreach ($this->connection->iterateColumn('SELECT id FROM app_user ORDER BY created_at') as $id) {
            $user = \is_string($id) ? $this->users->find(Uuid::fromBinary($id)) : null;
            if (null !== $user) {
                yield $user;
            }
        }
    }

    private function exercises(User $user): int
    {
        $rows = $this->connection->iterateAssociative(
            'SELECT exercise_type, success, duration_ms, item_count, source_type, source_id, occurred_at, local_date, metadata
               FROM activity_log_entry WHERE user_id = ? ORDER BY occurred_at, id',
            [$user->getId()->toBinary()],
            [ParameterType::BINARY],
        );
        /** @var array<string, int> $byDay exercise XP so far, by local day */
        $byDay = [];
        $batch = [];
        $total = 0;
        foreach ($rows as $row) {
            $type = ExerciseType::tryFrom(self::string($row['exercise_type']));
            if (null === $type) {
                continue;
            }
            $day = self::string($row['local_date']);
            $metadata = json_decode(self::string($row['metadata']), true);
            $xp = min(
                XpRules::exercise($type, (bool) $row['success'], is_numeric($row['duration_ms']) ? (int) $row['duration_ms'] : 0, is_numeric($row['item_count']) ? (int) $row['item_count'] : 1, \is_array($metadata) ? $metadata : []),
                max(0, XpRules::DAILY_EXERCISE_CAP - ($byDay[$day] ?? 0)),
            );
            $byDay[$day] = ($byDay[$day] ?? 0) + $xp;
            $total += $xp;
            $runId = \is_array($metadata) ? ($metadata['trainingRunId'] ?? null) : null;
            $batch[] = [
                XpKind::Exercise, XpRules::ledgerModule($type), $xp, self::string($row['source_type']), self::string($row['source_id']),
                \is_string($runId) && Uuid::isValid($runId) ? Uuid::fromString($runId)->toBinary() : null, $day, self::string($row['occurred_at']),
            ];
            if (\count($batch) >= self::BATCH) {
                $this->insert($user, $batch);
                $batch = [];
            }
        }
        $this->insert($user, $batch);

        return $total;
    }

    private function bonuses(User $user): int
    {
        $id = $user->getId()->toBinary();
        $zone = $user->getDateTimeZone();
        $rows = [];
        foreach ($this->connection->fetchAllAssociative(
            "SELECT BIN_TO_UUID(id) AS id, closed_at, JSON_UNQUOTE(JSON_EXTRACT(parcours, '$.effort')) AS effort,
                    JSON_CONTAINS(JSON_EXTRACT(steps, '$[*].status'), '\"skipped\"') AS skipped
               FROM training_session WHERE user_id = ? AND status = ? AND closed_at IS NOT NULL",
            [$id, SessionStatus::Completed->value],
            [ParameterType::BINARY],
        ) as $row) {
            // As AwardBonusXp: a parcours with a tier skipped earns no bonus.
            if (\is_string($row['effort']) && \in_array($row['skipped'], [1, '1'], true)) {
                continue;
            }
            $rows[] = [XpKind::Session, null, XpRules::sessionBonus(\is_string($row['effort']) ? $row['effort'] : null), 'training_session', self::string($row['id']), null, null, self::string($row['closed_at'])];
        }
        foreach ($this->connection->fetchAllAssociative(
            'SELECT BIN_TO_UUID(s.id) AS set_id, c.number, c.run, c.completed_at
               FROM woodpecker_cycle c JOIN woodpecker_set s ON s.id = c.set_id
              WHERE s.user_id = ? AND c.status = ? AND c.completed_at IS NOT NULL',
            [$id, CycleStatus::Completed->value],
            [ParameterType::BINARY],
        ) as $row) {
            $source = AwardBonusXp::cycleSource(self::string($row['set_id']), is_numeric($row['number']) ? (int) $row['number'] : 0, is_numeric($row['run']) ? (int) $row['run'] : 0);
            $rows[] = [XpKind::Cycle, Module::Woodpecker->value, XpRules::CYCLE_COMPLETED, 'woodpecker_cycle', $source, null, null, self::string($row['completed_at'])];
        }
        foreach ($this->connection->fetchAllAssociative(
            'SELECT BIN_TO_UUID(id) AS id, completed_at FROM woodpecker_set WHERE user_id = ? AND status = ? AND completed_at IS NOT NULL',
            [$id, SetStatus::Completed->value],
            [ParameterType::BINARY],
        ) as $row) {
            $rows[] = [XpKind::Set, Module::Woodpecker->value, XpRules::SET_COMPLETED, 'woodpecker_set', self::string($row['id']), null, null, self::string($row['completed_at'])];
        }
        // The first series validating each orientation of the coordinates.
        $validated = [];
        foreach ($this->connection->fetchAllAssociative(
            'SELECT orientation, run_id, closed_at FROM coordinates_series
              WHERE user_id = ? AND validated = 1 AND closed_at IS NOT NULL ORDER BY closed_at',
            [$id],
            [ParameterType::BINARY],
        ) as $row) {
            $orientation = self::string($row['orientation']);
            if (isset($validated[$orientation])) {
                continue;
            }
            $validated[$orientation] = true;
            $source = AwardBonusXp::validationSource($user->getId()->toRfc4122(), $orientation);
            $rows[] = [XpKind::Validation, Module::Coordinates->value, XpRules::COORDINATES_VALIDATED, AwardBonusXp::VALIDATION_SOURCE, $source, self::string($row['run_id']), null, self::string($row['closed_at'])];
        }
        // The puzzle streak records, flagged when the streak ended.
        foreach ($this->connection->fetchAllAssociative(
            'SELECT BIN_TO_UUID(id) AS id, run_id, ended_at FROM puzzle_streak
              WHERE user_id = ? AND record = 1 AND ended_at IS NOT NULL',
            [$id],
            [ParameterType::BINARY],
        ) as $row) {
            $rows[] = [XpKind::Record, Module::PuzzleStreak->value, XpRules::STREAK_RECORD, AwardBonusXp::RECORD_SOURCE, self::string($row['id']), self::string($row['run_id']), null, self::string($row['ended_at'])];
        }
        // The blindfold puzzles pardoned on the miss sheet, on the day of the miss.
        foreach ($this->connection->fetchAllAssociative(
            'SELECT BIN_TO_UUID(id) AS id, run_id, submitted_at FROM blindfold_puzzle_attempt
              WHERE user_id = ? AND pardon = ? AND submitted_at IS NOT NULL',
            [$id, Pardon::Loss->value],
            [ParameterType::BINARY],
        ) as $row) {
            $rows[] = [XpKind::Pardon, Module::Blindfold->value, XpRules::BLINDFOLD_PARDON, AwardBonusXp::PARDON_SOURCE, self::string($row['id']), self::string($row['run_id']), null, self::string($row['submitted_at'])];
        }
        // The steps of the mind map paths completed, with the XP of their step today.
        foreach ($this->connection->fetchAllAssociative(
            'SELECT BIN_TO_UUID(p.id) AS id, p.capacity, p.step, p.completed_at,
                    (SELECT a.run_id FROM mind_map_attempt a
                      WHERE a.user_id = p.user_id AND a.capacity = p.capacity AND a.step = p.step AND a.completed = 1 LIMIT 1) AS run_id
               FROM mind_map_progress p WHERE p.user_id = ? AND p.completed_at IS NOT NULL',
            [$id],
            [ParameterType::BINARY],
        ) as $row) {
            $capacity = Capacity::tryFrom(self::string($row['capacity']));
            $step = null === $capacity ? null : Paths::find($capacity, self::string($row['step']))['step'] ?? null;
            if (null === $step || $step->xp <= 0) {
                continue;
            }
            $runId = \is_string($row['run_id']) ? $row['run_id'] : null;
            $rows[] = [XpKind::MindMap, null, $step->xp, AwardBonusXp::MIND_MAP_SOURCE, self::string($row['id']), $runId, null, self::string($row['completed_at'])];
        }
        // The local day of each bonus, in the user's timezone.
        foreach ($rows as $i => $row) {
            $rows[$i][6] = LocalDate::of(new \DateTimeImmutable($row[7], new \DateTimeZone('UTC')), $zone)->format('Y-m-d');
        }
        $this->insert($user, $rows);

        return array_sum(array_column($rows, 2));
    }

    /**
     * @param list<array{0: XpKind, 1: string|null, 2: int, 3: string, 4: string, 5: string|null, 6: string|null, 7: string}> $rows
     *                                                                                                                       kind, module, xp, source type, source id, run id (binary), local date, occurred at (UTC)
     */
    private function insert(User $user, array $rows): void
    {
        if ([] === $rows) {
            return;
        }
        $params = [];
        $types = [];
        foreach ($rows as [$kind, $module, $xp, $sourceType, $sourceId, $runId, $day, $at]) {
            array_push($params, Uuid::v7()->toBinary(), $user->getId()->toBinary(), $kind->value, $module, $xp, $sourceType, $sourceId, $runId, $day, $at);
            array_push($types, ParameterType::BINARY, ParameterType::BINARY, ParameterType::STRING, ParameterType::STRING, ParameterType::INTEGER, ParameterType::STRING, ParameterType::STRING, ParameterType::BINARY, ParameterType::STRING, ParameterType::STRING);
        }
        $this->connection->executeStatement(
            'INSERT INTO gamification_xp_entry (id, user_id, kind, module, xp, source_type, source_id, training_run_id, local_date, occurred_at) VALUES '
            .implode(', ', array_fill(0, \count($rows), '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'))
            .' ON DUPLICATE KEY UPDATE id = id',
            $params,
            $types,
        );
    }

    private static function string(mixed $value): string
    {
        return \is_string($value) ? $value : throw new \UnexpectedValueException('Not a string.');
    }
}
