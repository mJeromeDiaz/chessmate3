<?php

declare(strict_types=1);

namespace App\Woodpecker\Integration;

use App\Entity\User;
use App\Puzzle\Selection\ExclusionProviderInterface;
use App\Repository\Woodpecker\SetPuzzleRepository;
use Doctrine\DBAL\Connection;

/**
 * Keeps the puzzles of the user's active or paused set out of the rated selection (validated
 * rule): two indexed probes, the ongoing set by active_user_id, then (set_id, puzzle_id).
 */
final class ActiveSetExclusion implements ExclusionProviderInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly SetPuzzleRepository $setPuzzles,
    ) {
    }

    public function excludedAmong(User $user, array $puzzleIds): array
    {
        $setId = $this->connection->fetchOne(
            'SELECT id FROM woodpecker_set WHERE active_user_id = :user',
            ['user' => $user->getId()->toBinary()],
        );

        return \is_string($setId) ? $this->setPuzzles->findPuzzleIdsInSet($setId, $puzzleIds) : [];
    }
}
