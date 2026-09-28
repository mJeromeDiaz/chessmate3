<?php

declare(strict_types=1);

namespace App\Puzzle\Selection;

use App\Entity\User;
use App\Repository\Puzzle\AttemptRepository;
use Doctrine\DBAL\Connection;
use Random\Randomizer;

/**
 * Picks a puzzle near the user's level (docs/PUZZLES.md, "Adaptive selection").
 *
 * Target window: centre = rating − 100 + difficulty offset (a puzzle rated 100 below the player is
 * solved ~64% of the time: Glicko-2 expected score), half-width = 75 + RD/2 (wide while the rating
 * is uncertain). The window doubles up to three times when nothing is left in it.
 *
 * Randomness without ORDER BY RAND(): draw a rating t in the window and a random key k, then read
 * the next CANDIDATES rows after (t, k) in (rating, random_key) order — one index range scan on
 * `idx_puzzle_selection` (no theme) or on the clustered key of `puzzle_theme_membership` (one scan
 * per theme) — wrapping around to the start of the window if the end is reached. Candidates the user
 * already played rated are dropped with one indexed probe each, so the cost does not grow with the
 * user's history; if they are all played, a new (t, k) is drawn.
 */
final class PuzzleSelector
{
    public const CANDIDATES = 30;
    public const DRAWS_PER_WINDOW = 3;
    /** @var list<int> */
    public const WIDENING_FACTORS = [1, 2, 4, 8];
    public const TARGET_OFFSET = -100;
    public const BASE_HALF_WIDTH = 75;
    private const MAX_RANDOM_KEY = 0xFFFFFFFF;

    private readonly Randomizer $randomizer;

    public function __construct(
        private readonly Connection $connection,
        private readonly AttemptRepository $attempts,
        ?Randomizer $randomizer = null,
    ) {
        $this->randomizer = $randomizer ?? new Randomizer();
    }

    /**
     * @return array{int, int} centre and half-width of the initial window
     */
    public function window(float $rating, float $deviation, SelectionCriteria $criteria): array
    {
        return [
            (int) round($rating + self::TARGET_OFFSET + $criteria->difficulty->ratingOffset()),
            (int) round(self::BASE_HALF_WIDTH + $deviation / 2),
        ];
    }

    /**
     * @return int|null a puzzle id, or null when nothing matches even in the widest window
     */
    public function select(User $user, float $rating, float $deviation, SelectionCriteria $criteria): ?int
    {
        [$centre, $halfWidth] = $this->window($rating, $deviation, $criteria);

        foreach (self::WIDENING_FACTORS as $factor) {
            $low = max(0, $centre - $halfWidth * $factor);
            $high = max($low, $centre + $halfWidth * $factor);

            for ($draw = 0; $draw < self::DRAWS_PER_WINDOW; ++$draw) {
                $candidates = $this->candidates($criteria->themeIds, $low, $high);
                if ([] === $candidates) {
                    break; // nothing at all in this window: widen
                }

                $fresh = array_values(array_diff($candidates, $this->attempts->findRatedPuzzleIds($user, $candidates)));
                if ([] !== $fresh) {
                    return $fresh[$this->randomizer->getInt(0, \count($fresh) - 1)];
                }
            }
        }

        return null;
    }

    /**
     * @param list<int> $themeIds
     *
     * @return list<int>
     */
    private function candidates(array $themeIds, int $low, int $high): array
    {
        $rating = $this->randomizer->getInt($low, $high);
        $key = $this->randomizer->getInt(0, self::MAX_RANDOM_KEY);

        if ([] === $themeIds) {
            return $this->seek('puzzle', 'id', 'selectable = 1', [], $low, $high, $rating, $key);
        }

        $ids = [];
        foreach ($themeIds as $themeId) {
            $ids[] = $this->seek('puzzle_theme_membership', 'puzzle_id', 'theme_id = :theme', ['theme' => $themeId], $low, $high, $rating, $key);
        }

        return array_values(array_unique(array_merge(...$ids)));
    }

    /**
     * Reads up to CANDIDATES ids after (rating, key) within [low, high], wrapping around.
     *
     * @param array<string, int> $params
     *
     * @return list<int>
     */
    private function seek(string $table, string $idColumn, string $filter, array $params, int $low, int $high, int $rating, int $key): array
    {
        $params += ['low' => $low, 'high' => $high, 'rating' => $rating, 'key' => $key];

        $ids = $this->connection->fetchFirstColumn(
            "SELECT $idColumn FROM $table WHERE $filter
             AND ((rating = :rating AND random_key >= :key) OR (rating > :rating AND rating <= :high))
             ORDER BY rating, random_key LIMIT ".self::CANDIDATES,
            $params,
        );

        if (\count($ids) < self::CANDIDATES) {
            $ids = array_merge($ids, $this->connection->fetchFirstColumn(
                "SELECT $idColumn FROM $table WHERE $filter
                 AND ((rating >= :low AND rating < :rating) OR (rating = :rating AND random_key < :key))
                 ORDER BY rating, random_key LIMIT ".(self::CANDIDATES - \count($ids)),
                $params,
            ));
        }

        return array_map(static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0, $ids);
    }
}
