<?php

declare(strict_types=1);

namespace App\Dashboard\Training;

use App\Dashboard\Period;
use App\Entity\User;
use App\Enum\Training\SessionStatus;
use Doctrine\DBAL\Connection;

/**
 * The sessions of a period (docs/DASHBOARD.md): those started in it and closed (the active one is
 * left out), by final status, and their played time, the sum of their runs' `durationMs` (as in
 * `SessionClosed`). The average is over the sessions where something was played: one that expired
 * without a run does not pull it down.
 *
 * @phpstan-type Sessions array{closed: int, completed: int, abandoned: int, expired: int, playedMs: int, averageMs: int|null}
 */
final class SessionStats
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return Sessions
     */
    public function compute(User $user, Period $period): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT s.status, COUNT(*) AS n, SUM(t.ms) AS ms, SUM(t.ms > 0) AS played
               FROM training_session s
               LEFT JOIN (
                    SELECT parent_id, SUM(CAST(summary->>'$.durationMs' AS UNSIGNED)) AS ms
                      FROM training_run
                     WHERE user_id = :user AND parent_id IS NOT NULL AND started_at >= :since
                     GROUP BY parent_id
               ) t ON t.parent_id = s.id
              WHERE s.user_id = :user AND s.started_at >= :since AND s.status <> :active
              GROUP BY s.status",
            ['user' => $user->getId()->toBinary(), 'since' => $period->since->format('Y-m-d H:i:s'), 'active' => SessionStatus::Active->value],
        );

        $stats = ['closed' => 0, 'completed' => 0, 'abandoned' => 0, 'expired' => 0, 'playedMs' => 0, 'averageMs' => null];
        $played = 0;
        foreach ($rows as $row) {
            $count = self::int($row['n']);
            $status = SessionStatus::tryFrom(\is_string($row['status']) ? $row['status'] : '');
            match ($status) {
                SessionStatus::Completed => $stats['completed'] += $count,
                SessionStatus::Abandoned => $stats['abandoned'] += $count,
                SessionStatus::Expired => $stats['expired'] += $count,
                default => null,
            };
            $stats['closed'] += $count;
            $stats['playedMs'] += self::int($row['ms']);
            $played += self::int($row['played']);
        }
        $stats['averageMs'] = $played > 0 ? intdiv($stats['playedMs'], $played) : null;

        return $stats;
    }

    /** COUNT and SUM come back as numeric strings (SUM of no row: null). */
    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
