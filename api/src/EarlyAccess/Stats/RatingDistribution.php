<?php

declare(strict_types=1);

namespace App\EarlyAccess\Stats;

use Doctrine\DBAL\Connection;

/**
 * The players' Lichess "Elo" ({@see LichessRating}), today: a histogram by bands of 100 points,
 * every band listed from the lowest to the highest one held, and its median. Only accounts with a
 * linked Lichess account have one; no other rating stands in for it.
 *
 * @phpstan-type Band array{from: int, count: int}
 * @phpstan-type Distribution array{bands: list<Band>, bandWidth: int, median: int|null, rated: int, linkedUnrated: int, notLinked: int, byPerf: array<string, int>}
 */
final class RatingDistribution
{
    public const BAND_WIDTH = 100;

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return Distribution
     */
    public function compute(): array
    {
        $rows = $this->connection->fetchFirstColumn("SELECT metadata FROM auth_identity WHERE provider = 'lichess'");
        $players = Sql::int($this->connection->fetchOne('SELECT COUNT(*) FROM app_user'));

        $ratings = [];
        $byPerf = array_fill_keys(LichessRating::PERFS, 0);
        foreach ($rows as $json) {
            $metadata = \is_string($json) ? json_decode($json, true) : null;
            $picked = \is_array($metadata) ? LichessRating::pick($metadata) : null;
            if (null !== $picked) {
                $ratings[] = $picked['rating'];
                ++$byPerf[$picked['perf']];
            }
        }

        $bands = [];
        if ([] !== $ratings) {
            $counts = array_count_values(array_map(self::band(...), $ratings));
            for ($band = self::band(min($ratings)), $last = self::band(max($ratings)); $band <= $last; $band += self::BAND_WIDTH) {
                $bands[] = ['from' => $band, 'count' => $counts[$band] ?? 0];
            }
        }

        return [
            'bands' => $bands,
            'bandWidth' => self::BAND_WIDTH,
            'median' => Sql::median($ratings),
            'rated' => \count($ratings),
            'linkedUnrated' => \count($rows) - \count($ratings),
            'notLinked' => max(0, $players - \count($rows)),
            'byPerf' => $byPerf,
        ];
    }

    private static function band(int $rating): int
    {
        return intdiv($rating, self::BAND_WIDTH) * self::BAND_WIDTH;
    }
}
