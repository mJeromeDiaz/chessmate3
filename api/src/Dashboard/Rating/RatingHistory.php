<?php

declare(strict_types=1);

namespace App\Dashboard\Rating;

use App\Dashboard\Period;
use App\Entity\User;
use Doctrine\DBAL\Connection;

/**
 * The puzzle rating curve (docs/DASHBOARD.md): one point per local day with a rating change, the
 * rating at the end of that day, read from puzzle_rating_change (idx_puzzle_rating_change_user_date).
 * The rating known when the period starts opens the curve on its first day, so a quiet week
 * still draws a line.
 *
 * @phpstan-type Point array{date: string, rating: int}
 */
final class RatingHistory
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return list<Point> oldest first
     */
    public function points(User $user, Period $period): array
    {
        $params = ['user' => $user->getId()->toBinary(), 'since' => $period->since->format('Y-m-d H:i:s')];
        $before = $this->connection->fetchOne(
            'SELECT rating_after FROM puzzle_rating_change
              WHERE user_id = :user AND created_at < :since
              ORDER BY created_at DESC, id DESC LIMIT 1',
            $params,
        );
        $rows = $this->connection->fetchAllAssociative(
            'SELECT rating_after, created_at FROM puzzle_rating_change
              WHERE user_id = :user AND created_at >= :since
              ORDER BY created_at, id',
            $params,
        );

        $timezone = $user->getDateTimeZone();
        $utc = new \DateTimeZone('UTC');
        /** @var array<string, int> $byDay */
        $byDay = [];
        if (is_numeric($before)) {
            $byDay[$period->fromDate()] = (int) round((float) $before);
        }
        foreach ($rows as $row) {
            if (!\is_string($row['created_at']) || !is_numeric($row['rating_after'])) {
                continue;
            }
            $day = (new \DateTimeImmutable($row['created_at'], $utc))->setTimezone($timezone)->format('Y-m-d');
            $byDay[$day] = (int) round((float) $row['rating_after']);
        }

        $points = [];
        foreach ($byDay as $date => $rating) {
            $points[] = ['date' => (string) $date, 'rating' => $rating];
        }

        return $points;
    }
}
