<?php

declare(strict_types=1);

namespace App\EarlyAccess\Stats;

use App\Dashboard\Period;
use Doctrine\DBAL\Connection;

/**
 * How much the community plays (docs/EARLY_ACCESS.md), from the activity log:
 *
 * - a player is **active** with at least one exercise done in the last 7 days (rolling, now);
 * - active players by day, on each player's own local day (the one logged with the exercise);
 * - the average training time **per active player and per week**: the time played in a week,
 *   divided by the players who played that week; over the period, the total time divided by the
 *   player-weeks (a player who played 3 weeks counts 3 times).
 *
 * @phpstan-type Day array{date: string, activePlayers: int}
 * @phpstan-type Week array{start: string, activePlayers: int, durationMs: int, averageMs: int|null}
 * @phpstan-type Summary array{players: int, active: int, inactive: int, days: list<Day>, weeks: list<Week>, averageWeeklyMs: int|null}
 */
final class CommunityActivity
{
    public const ACTIVE_DAYS = 7;

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return Summary
     */
    public function compute(Period $period, \DateTimeImmutable $now): array
    {
        $players = Sql::int($this->connection->fetchOne('SELECT COUNT(*) FROM app_user'));
        $active = $this->activeCount($now);

        $rows = $this->connection->fetchAllAssociative(
            'SELECT local_date, user_id, SUM(duration_ms) AS ms
               FROM activity_log_entry
              WHERE local_date BETWEEN :from AND :today
              GROUP BY local_date, user_id',
            ['from' => $period->fromDate(), 'today' => $period->todayDate()],
        );

        $days = array_fill_keys(Sql::days($period->from, $period->today), 0);
        /** @var array<string, array{players: array<string, true>, ms: int}> $weeks */
        $weeks = [];
        $last = Sql::monday($period->today);
        for ($week = Sql::monday($period->from); $week <= $last; $week = $week->modify('+7 days')) {
            $weeks[$week->format('Y-m-d')] = ['players' => [], 'ms' => 0];
        }
        foreach ($rows as $row) {
            if (!\is_string($row['local_date']) || !\is_string($row['user_id'])) {
                continue;
            }
            if (isset($days[$row['local_date']])) {
                ++$days[$row['local_date']];
            }
            $start = Sql::monday(new \DateTimeImmutable($row['local_date']))->format('Y-m-d');
            if (isset($weeks[$start])) {
                $weeks[$start]['players'][bin2hex($row['user_id'])] = true;
                $weeks[$start]['ms'] += Sql::int($row['ms']);
            }
        }

        $dayList = [];
        foreach ($days as $date => $count) {
            $dayList[] = ['date' => $date, 'activePlayers' => $count];
        }
        $weekList = [];
        $playerWeeks = 0;
        $totalMs = 0;
        foreach ($weeks as $start => $week) {
            $count = \count($week['players']);
            $playerWeeks += $count;
            $totalMs += $week['ms'];
            $weekList[] = ['start' => $start, 'activePlayers' => $count, 'durationMs' => $week['ms'], 'averageMs' => 0 === $count ? null : intdiv($week['ms'], $count)];
        }

        return [
            'players' => $players,
            'active' => $active,
            'inactive' => max(0, $players - $active),
            'days' => $dayList,
            'weeks' => $weekList,
            'averageWeeklyMs' => 0 === $playerWeeks ? null : intdiv($totalMs, $playerWeeks),
        ];
    }

    /**
     * Players with an exercise since now - 7 days. The local_date bound (a day before the UTC date,
     * the widest timezone gap) only lets the index skip older rows; occurred_at decides.
     */
    private function activeCount(\DateTimeImmutable $now): int
    {
        $since = self::activeSince($now);

        return Sql::int($this->connection->fetchOne(
            'SELECT COUNT(DISTINCT user_id) FROM activity_log_entry WHERE local_date >= :day AND occurred_at >= :since',
            ['day' => $since->setTimezone(new \DateTimeZone('UTC'))->modify('-1 day')->format('Y-m-d'), 'since' => Sql::instant($since)],
        ));
    }

    public static function activeSince(\DateTimeImmutable $now): \DateTimeImmutable
    {
        return $now->modify(\sprintf('-%d days', self::ACTIVE_DAYS));
    }
}
