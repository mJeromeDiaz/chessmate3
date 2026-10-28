<?php

declare(strict_types=1);

namespace App\Arena\Master;

use App\Entity\User;
use App\Enum\Gamification\Trophy;
use App\Gamification\Trophy\TrophySourceInterface;
use App\Repository\Arena\GameRepository;

/**
 * The exceptional badges of the professors (docs/ARENA.md, § 2 bis): their feat reached, at its
 * instant ({@see Masters}); the professor beaten in the arena, at the first win.
 *
 * @phpstan-import-type Progress from \App\Gamification\Trophy\TrophyProgress
 */
final readonly class MasterTrophies implements TrophySourceInterface
{
    private const FEATS = [
        'master_lizy' => 'lizy',
        'master_albert' => 'albert',
        'master_aaron' => 'aaron',
        'master_ray' => 'ray',
    ];
    private const BEATEN = [
        'master_lizy_beaten' => 'lizy',
        'master_albert_beaten' => 'albert',
        'master_aaron_beaten' => 'aaron',
        'master_ray_beaten' => 'ray',
    ];

    public function __construct(
        private Masters $masters,
        private GameRepository $games,
    ) {
    }

    public function supports(Trophy $trophy): bool
    {
        return isset(self::FEATS[$trophy->value]) || isset(self::BEATEN[$trophy->value]);
    }

    public function progress(User $user, Trophy $trophy): array
    {
        $master = self::FEATS[$trophy->value] ?? null;
        if (null !== $master) {
            $feat = $this->masters->one($user, $master);

            return ['current' => $feat['current'], 'goal' => $feat['goal'], 'reachedAt' => $feat['reachedAt']];
        }
        $wonAt = ($this->games->records($user)[self::BEATEN[$trophy->value] ?? ''] ?? null)['firstWonAt'] ?? null;

        return ['current' => null === $wonAt ? 0 : 1, 'reachedAt' => $wonAt];
    }
}
