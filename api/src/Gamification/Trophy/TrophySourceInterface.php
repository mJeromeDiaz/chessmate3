<?php

declare(strict_types=1);

namespace App\Gamification\Trophy;

use App\Entity\User;
use App\Enum\Gamification\Trophy;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Trophies whose progress another domain reads (the professors of the arena, docs/ARENA.md): the
 * Gamification domain never depends on the domains that implement it. Autoconfigured.
 *
 * @phpstan-import-type Progress from TrophyProgress
 */
#[AutoconfigureTag(self::TAG)]
interface TrophySourceInterface
{
    public const TAG = 'app.gamification.trophy_source';

    public function supports(Trophy $trophy): bool;

    /**
     * The progress towards the trophy and, when reached, the instant of the feat; `goal` replaces
     * the trophy's own when the source knows it better.
     *
     * @return Progress
     */
    public function progress(User $user, Trophy $trophy): array;
}
