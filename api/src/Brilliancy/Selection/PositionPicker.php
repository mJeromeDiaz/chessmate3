<?php

declare(strict_types=1);

namespace App\Brilliancy\Selection;

use App\Brilliancy\BrilliancyRules;
use App\Entity\Training\Run;
use App\Enum\Brilliancy\Kind;
use App\Repository\Brilliancy\AttemptRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Random\Randomizer;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Picks the next sacrifice of a run (docs/BRILLIANCY.md, § 3) from `brilliancy_position`: of the
 * kind asked, around the player's puzzle rating, widened when a window has nothing left; never a
 * puzzle already served in the run, and one the player was never shown while a few random draws
 * find one. Without ORDER BY RAND(): a random (rating, key) point, then the rows after it in the
 * clustered key's order, wrapping around (as {@see \App\Puzzle\Selection\RandomSeeker} does).
 */
final class PositionPicker
{
    private const MAX_RANDOM_KEY = 0xFFFFFFFF;

    private readonly Randomizer $randomizer;

    public function __construct(
        #[Autowire(service: 'doctrine.dbal.catalog_connection')]
        private readonly Connection $connection,
        private readonly AttemptRepository $attempts,
        ?Randomizer $randomizer = null,
    ) {
        $this->randomizer = $randomizer ?? new Randomizer();
    }

    /**
     * @return int|null the puzzle id, null when even the widest window has none left
     */
    public function pick(Run $run, Kind $kind, int $target): ?int
    {
        $inRun = $this->attempts->puzzleIdsOfRun($run);
        foreach (BrilliancyRules::ranges($target) as [$low, $high]) {
            $fallback = null;
            for ($draw = 0; $draw < BrilliancyRules::DRAWS; ++$draw) {
                $candidates = array_values(array_diff($this->draw($kind, $low, $high), $inRun));
                if ([] === $candidates) {
                    continue;
                }
                $fresh = array_values(array_diff($candidates, $this->attempts->playedAmong($run->getUser(), $candidates)));
                if ([] !== $fresh) {
                    return $fresh[0];
                }
                $fallback ??= $candidates[0];
            }
            if (null !== $fallback) {
                return $fallback;
            }
        }

        return null;
    }

    /**
     * Up to CANDIDATES puzzle ids of $kind within [low, high], from one random point.
     *
     * @return list<int>
     */
    private function draw(Kind $kind, int $low, int $high): array
    {
        $params = [
            'ply' => $kind->ply(),
            'low' => $low,
            'high' => $high,
            'rating' => $this->randomizer->getInt($low, max($low, $high)),
            'key' => $this->randomizer->getInt(0, self::MAX_RANDOM_KEY),
        ];
        $types = array_fill_keys(array_keys($params), ParameterType::INTEGER);
        $limit = BrilliancyRules::CANDIDATES;

        $ids = $this->connection->fetchFirstColumn(
            'SELECT puzzle_id FROM brilliancy_position WHERE ply = :ply
             AND ((rating = :rating AND random_key >= :key) OR (rating > :rating AND rating <= :high))
             ORDER BY rating, random_key LIMIT '.$limit,
            $params,
            $types,
        );
        if (\count($ids) < $limit) {
            $ids = array_merge($ids, $this->connection->fetchFirstColumn(
                'SELECT puzzle_id FROM brilliancy_position WHERE ply = :ply
                 AND ((rating >= :low AND rating < :rating) OR (rating = :rating AND random_key < :key))
                 ORDER BY rating, random_key LIMIT '.($limit - \count($ids)),
                $params,
                $types,
            ));
        }

        return array_values(array_unique(array_map(static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0, $ids)));
    }
}
