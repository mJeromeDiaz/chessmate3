<?php

declare(strict_types=1);

namespace App\EarlyAccess\Stats;

use App\Dashboard\Period;
use Doctrine\DBAL\Connection;

/**
 * Accounts opened during the period, by day (the admin's local days) and by sign-up method
 * (docs/EARLY_ACCESS.md). The method is the one the invitation log noted when the key was spent;
 * an account opened without one (before early access, fixtures, e2e seeds) counts as `other`.
 *
 * ByMethod: count by method, every method listed.
 *
 * @phpstan-type ByMethod array{password: int, google: int, lichess: int, other: int}
 * @phpstan-type Day array{date: string, total: int, byMethod: ByMethod}
 * @phpstan-type Summary array{total: int, byMethod: ByMethod, days: list<Day>, accounts: int, unverified: int, suspended: int}
 */
final class Signups
{
    public const METHODS = ['password', 'google', 'lichess'];
    public const OTHER = 'other';

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return Summary
     */
    public function compute(Period $period, \DateTimeZone $timezone): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT u.created_at,
                    (SELECT JSON_UNQUOTE(JSON_EXTRACT(l.details, '$.method'))
                       FROM early_access_invitation_log l
                      WHERE l.actor_id = u.id AND l.action = 'key_used'
                      LIMIT 1) AS method
               FROM app_user u
              WHERE u.created_at >= :since",
            ['since' => Sql::instant($period->since)],
        );

        $empty = self::emptyByMethod();
        $days = array_fill_keys(Sql::days($period->from, $period->today), $empty);
        $total = $empty;
        $utc = new \DateTimeZone('UTC');
        foreach ($rows as $row) {
            if (!\is_string($row['created_at'])) {
                continue;
            }
            $date = (new \DateTimeImmutable($row['created_at'], $utc))->setTimezone($timezone)->format('Y-m-d');
            $method = self::method($row['method']);
            if (isset($days[$date])) {
                ++$days[$date][$method];
            }
            ++$total[$method];
        }

        $list = [];
        foreach ($days as $date => $byMethod) {
            $list[] = ['date' => $date, 'total' => array_sum($byMethod), 'byMethod' => $byMethod];
        }

        $counts = $this->connection->fetchAssociative(
            'SELECT COUNT(*) AS accounts, SUM(email IS NOT NULL AND email_verified_at IS NULL) AS unverified, SUM(suspended_at IS NOT NULL) AS suspended FROM app_user',
        ) ?: [];

        return [
            'total' => array_sum($total),
            'byMethod' => $total,
            'days' => $list,
            'accounts' => Sql::int($counts['accounts'] ?? 0),
            'unverified' => Sql::int($counts['unverified'] ?? 0),
            'suspended' => Sql::int($counts['suspended'] ?? 0),
        ];
    }

    /**
     * @return 'password'|'google'|'lichess'|'other'
     */
    public static function method(mixed $method): string
    {
        return match ($method) {
            'password', 'google', 'lichess' => $method,
            default => self::OTHER,
        };
    }

    /**
     * @return ByMethod
     */
    private static function emptyByMethod(): array
    {
        return ['password' => 0, 'google' => 0, 'lichess' => 0, 'other' => 0];
    }
}
