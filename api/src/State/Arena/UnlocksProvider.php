<?php

declare(strict_types=1);

namespace App\State\Arena;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Arena\Unlocks as UnlocksView;
use App\Arena\Roster\ArenaReader;
use App\Arena\Unlock\Unlocks;
use App\Security\AuthenticatedUser;
use Psr\Clock\ClockInterface;

/**
 * GET /arena/unlocks: the bosses that arrived in the current player's arena, not shown yet (those
 * that just became available written first).
 *
 * @phpstan-import-type BossView from ArenaReader
 *
 * @implements ProviderInterface<UnlocksView>
 */
final class UnlocksProvider implements ProviderInterface
{
    public function __construct(
        private readonly ArenaReader $reader,
        private readonly Unlocks $unlocks,
        private readonly AuthenticatedUser $authenticatedUser,
        private readonly ClockInterface $clock,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): UnlocksView
    {
        $user = $this->authenticatedUser->get();
        $arena = $this->reader->read($user);

        return self::view($arena['bosses'], $this->unlocks->sync($user, $this->clock->now(), $arena['statuses']));
    }

    /**
     * @param list<BossView> $bosses
     * @param list<string>   $pending
     */
    public static function view(array $bosses, array $pending): UnlocksView
    {
        $view = new UnlocksView();
        $view->bosses = array_values(array_filter($bosses, static fn (array $boss): bool => \in_array($boss['character'], $pending, true)));

        return $view;
    }
}
