<?php

declare(strict_types=1);

namespace App\Puzzle\Selection;

use Doctrine\DBAL\Connection;
use Random\Randomizer;

/**
 * Random puzzles without ORDER BY RAND() (docs/PUZZLES.md, "Adaptive selection"): draw a rating t
 * in [low, high] and a random key k, then read the next rows after (t, k) in (rating, random_key)
 * order — one index range scan on `idx_puzzle_selection` (no theme) or on the clustered key of
 * `puzzle_theme_membership` (one per theme, OR) — wrapping around to the start of the range.
 * Selectable puzzles only. Shared by the rated selection and the Woodpecker set generator.
 */
final class RandomSeeker
{
    private const MAX_RANDOM_KEY = 0xFFFFFFFF;

    private readonly Randomizer $randomizer;

    public function __construct(
        private readonly Connection $connection,
        ?Randomizer $randomizer = null,
    ) {
        $this->randomizer = $randomizer ?? new Randomizer();
    }

    /**
     * Up to $limit puzzle ids per theme (or $limit without theme) from one random point.
     *
     * @param list<int> $themeIds puzzle_theme ids (OR); empty = any theme
     *
     * @return list<int>
     */
    public function draw(array $themeIds, int $low, int $high, int $limit): array
    {
        $rating = $this->randomizer->getInt($low, max($low, $high));
        $key = $this->randomizer->getInt(0, self::MAX_RANDOM_KEY);

        if ([] === $themeIds) {
            return $this->seek('puzzle', 'id', 'selectable = 1', [], $low, $high, $rating, $key, $limit);
        }

        $ids = [];
        foreach ($themeIds as $themeId) {
            $ids[] = $this->seek('puzzle_theme_membership', 'puzzle_id', 'theme_id = :theme', ['theme' => $themeId], $low, $high, $rating, $key, $limit);
        }

        return array_values(array_unique(array_merge(...$ids)));
    }

    /**
     * Reads up to $limit ids after (rating, key) within [low, high], wrapping around.
     *
     * @param array<string, int> $params
     *
     * @return list<int>
     */
    private function seek(string $table, string $idColumn, string $filter, array $params, int $low, int $high, int $rating, int $key, int $limit): array
    {
        $params += ['low' => $low, 'high' => $high, 'rating' => $rating, 'key' => $key];

        $ids = $this->connection->fetchFirstColumn(
            "SELECT $idColumn FROM $table WHERE $filter
             AND ((rating = :rating AND random_key >= :key) OR (rating > :rating AND rating <= :high))
             ORDER BY rating, random_key LIMIT ".$limit,
            $params,
        );

        if (\count($ids) < $limit) {
            $ids = array_merge($ids, $this->connection->fetchFirstColumn(
                "SELECT $idColumn FROM $table WHERE $filter
                 AND ((rating >= :low AND rating < :rating) OR (rating = :rating AND random_key < :key))
                 ORDER BY rating, random_key LIMIT ".($limit - \count($ids)),
                $params,
            ));
        }

        return array_map(static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0, $ids);
    }
}
