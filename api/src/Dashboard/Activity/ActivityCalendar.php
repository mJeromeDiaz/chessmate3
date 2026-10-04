<?php

declare(strict_types=1);

namespace App\Dashboard\Activity;

use App\Dashboard\Period;
use App\Entity\User;
use Doctrine\DBAL\Connection;

/**
 * The activity heatmap (docs/DASHBOARD.md): exercises per local day, read from the activity log
 * through idx_activity_log_entry_user_date, and all-time totals per exercise type. Days are the
 * `local_date` written with each entry, so a past day never moves when the user changes timezone.
 *
 * @phpstan-type Day array{date: string, count: int, successCount: int, durationMs: int}
 * @phpstan-type Total array{count: int, successCount: int, durationMs: int}
 */
final class ActivityCalendar
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return list<Day> active days only, oldest first
     */
    public function days(User $user, Period $period): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT local_date, COUNT(*) AS n, SUM(success) AS ok, SUM(duration_ms) AS ms
               FROM activity_log_entry
              WHERE user_id = :user AND local_date BETWEEN :from AND :today
              GROUP BY local_date
              ORDER BY local_date',
            ['user' => $user->getId()->toBinary(), 'from' => $period->fromDate(), 'today' => $period->todayDate()],
        );

        return array_map(static fn (array $row): array => [
            'date' => \is_string($row['local_date']) ? $row['local_date'] : '',
            'count' => self::int($row['n']),
            'successCount' => self::int($row['ok']),
            'durationMs' => self::int($row['ms']),
        ], $rows);
    }

    /**
     * @return array<string, Total> by exercise type (ExerciseType values), all time
     */
    public function totals(User $user): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT exercise_type, COUNT(*) AS n, SUM(success) AS ok, SUM(duration_ms) AS ms
               FROM activity_log_entry
              WHERE user_id = :user
              GROUP BY exercise_type',
            ['user' => $user->getId()->toBinary()],
        );
        $totals = [];
        foreach ($rows as $row) {
            if (\is_string($row['exercise_type'])) {
                $totals[$row['exercise_type']] = ['count' => self::int($row['n']), 'successCount' => self::int($row['ok']), 'durationMs' => self::int($row['ms'])];
            }
        }
        ksort($totals);

        return $totals;
    }

    /** COUNT and SUM come back as numeric strings (SUM of no row: null). */
    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
