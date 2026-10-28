<?php

declare(strict_types=1);

namespace App\State\Arena;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Arena\Overview;
use App\Arena\ArenaRules;
use App\Arena\Game\Games;
use App\Arena\Roster\ArenaReader;
use App\Arena\Unlock\Unlocks;
use App\Security\AuthenticatedUser;
use App\Shop\Wallet\Wallets;
use Psr\Clock\ClockInterface;

/**
 * GET /arena (and after a purchase): the current player's only. The bosses that became available are written first (their
 * arrival waits to be shown), a game that ended by itself is closed.
 *
 * @implements ProviderInterface<Overview>
 */
final class OverviewProvider implements ProviderInterface
{
    public function __construct(
        private readonly ArenaReader $reader,
        private readonly Unlocks $unlocks,
        private readonly Games $games,
        private readonly Wallets $wallets,
        private readonly AuthenticatedUser $authenticatedUser,
        private readonly ClockInterface $clock,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): Overview
    {
        $user = $this->authenticatedUser->get();
        $now = $this->clock->now();
        $current = $this->games->current($user, $now);
        $arena = $this->reader->read($user);

        $view = new Overview();
        $view->bosses = $arena['bosses'];
        $view->cadences = ArenaRules::cadences();
        $view->defaultCadence = ArenaRules::DEFAULT_CADENCE;
        $view->badges = $arena['badges'];
        $view->balance = $this->wallets->balance($user->getId());
        $view->currentGame = $current?->getId()->toRfc4122();
        $view->pendingUnlocks = $this->unlocks->sync($user, $now, $arena['statuses']);

        return $view;
    }
}
