<?php

declare(strict_types=1);

namespace App\Puzzle\Selection;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Random\Randomizer;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Random puzzles without ORDER BY RAND() (docs/PUZZLES.md, "Adaptive selection"): draw a rating t
 * in [low, high] and a random key k, then read the next rows after (t, k) in (rating, random_key)
 * order — one index range scan on `idx_puzzle_selection` (no theme) or on the clustered key of
 * `puzzle_theme_membership` (one per theme, OR) — wrapping around to the start of the range.
 * Selectable puzzles only. Shared by the rated selection and the Woodpecker set generator. Reads
 * the catalogue's database only (docs/DEPLOY_OVH.md, § 3).
 *
 * While `puzzle_theme_membership` is rebuilt in place ({@see RebuildLock}), a themed draw throws
 * {@see SelectionUnavailableException}; a draw without theme reads `puzzle` and goes on.
 *
 * An opening draw (the opening sets, docs/COACH.md) reads `puzzle` on `idx_puzzle_opening`: the
 * family, the side the player plays ("w", "b"; null: both, one seek each).
 */
final class RandomSeeker
{
    private const MAX_RANDOM_KEY = 0xFFFFFFFF;

    private readonly Randomizer $randomizer;
    private readonly RebuildLock $rebuildLock;

    public function __construct(
        #[Autowire(service: 'doctrine.dbal.catalog_connection')]
        private readonly Connection $connection,
        ?Randomizer $randomizer = null,
    ) {
        $this->randomizer = $randomizer ?? new Randomizer();
        $this->rebuildLock = new RebuildLock($connection);
    }

    /**
     * Up to $limit puzzle ids per theme (or $limit without theme) from one random point.
     *
     * @param list<int> $themeIds puzzle_theme ids (OR); empty = any theme
     *
     * @return list<int>
     *
     * @throws SelectionUnavailableException themed draw during a rebuild of the selection index
     */
    public function draw(array $themeIds, int $low, int $high, int $limit): array
    {
        $rating = $this->randomizer->getInt($low, max($low, $high));
        $key = $this->randomizer->getInt(0, self::MAX_RANDOM_KEY);

        if ([] === $themeIds) {
            return $this->seek('puzzle', 'id', 'selectable = 1', [], $low, $high, $rating, $key, $limit);
        }

        if ($this->rebuildLock->isHeld()) {
            throw new SelectionUnavailableException();
        }
        $ids = [];
        foreach ($themeIds as $themeId) {
            $ids[] = $this->seek('puzzle_theme_membership', 'puzzle_id', 'theme_id = :theme', ['theme' => $themeId], $low, $high, $rating, $key, $limit);
        }

        return array_values(array_unique(array_merge(...$ids)));
    }

    /**
     * Up to $limit puzzle ids of an opening family (per side when $solverColor is null) from one
     * random point.
     *
     * @param string      $family      the family's tag ({@see \App\Puzzle\Opening\OpeningFamily::tag()})
     * @param string|null $solverColor "w", "b" or null (both)
     *
     * @return list<int>
     */
    public function drawOpening(string $family, ?string $solverColor, int $low, int $high, int $limit): array
    {
        $rating = $this->randomizer->getInt($low, max($low, $high));
        $key = $this->randomizer->getInt(0, self::MAX_RANDOM_KEY);
        $ids = [];
        foreach (null === $solverColor ? ['w', 'b'] : [$solverColor] as $color) {
            $ids[] = $this->seek('puzzle', 'id', 'opening_family = :family AND solver_color = :color AND selectable = 1', ['family' => $family, 'color' => $color], $low, $high, $rating, $key, $limit);
        }

        return array_values(array_unique(array_merge(...$ids)));
    }

    /**
     * Up to $limit selectable puzzle ids whose position has $pieces pieces (before the opponent's
     * first move), from one random point within [low, high].
     *
     * @return list<int>
     */
    public function drawByPieces(int $pieces, int $low, int $high, int $limit): array
    {
        $rating = $this->randomizer->getInt($low, max($low, $high));
        $key = $this->randomizer->getInt(0, self::MAX_RANDOM_KEY);

        return $this->seek('puzzle', 'id', 'piece_count = :pieces AND selectable = 1', ['pieces' => $pieces], $low, $high, $rating, $key, $limit);
    }

    /**
     * The selectable puzzles of an opening family within [low, high] (one side, or both).
     */
    public function countOpening(string $family, ?string $solverColor, int $low, int $high): int
    {
        $count = 0;
        foreach (null === $solverColor ? ['w', 'b'] : [$solverColor] as $color) {
            $found = $this->connection->fetchOne(
                'SELECT COUNT(*) FROM puzzle WHERE opening_family = :family AND solver_color = :color AND selectable = 1 AND rating BETWEEN :low AND :high',
                ['family' => $family, 'color' => $color, 'low' => $low, 'high' => $high],
                ['low' => ParameterType::INTEGER, 'high' => ParameterType::INTEGER],
            );
            $count += is_numeric($found) ? (int) $found : 0;
        }

        return $count;
    }

    /**
     * Reads up to $limit ids after (rating, key) within [low, high], wrapping around.
     *
     * @param array<string, int|string> $params
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
