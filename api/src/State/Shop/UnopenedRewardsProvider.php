<?php

declare(strict_types=1);

namespace App\State\Shop;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Shop\Reward;
use App\Security\AuthenticatedUser;
use App\Shop\Reward\SessionRewarder;
use App\Training\Session\SessionManager;

/**
 * GET /shop/rewards/unopened: the chests the player has not seen, oldest first (docs/SHOP.md,
 * § 4 bis), for the dashboard. The active session is reconciled first: one whose last module just
 * ended completes now and brings its chest.
 *
 * @implements ProviderInterface<Reward>
 */
final class UnopenedRewardsProvider implements ProviderInterface
{
    public function __construct(
        private readonly SessionRewarder $rewards,
        private readonly SessionManager $sessions,
        private readonly AuthenticatedUser $authenticatedUser,
    ) {
    }

    /**
     * @return list<Reward>
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $user = $this->authenticatedUser->get();
        $this->sessions->current($user);
        $balance = $this->rewards->balance($user->getId());

        return array_map(static function (array $unopened) use ($balance): Reward {
            $reward = Reward::of($unopened['reward'], $balance);
            $reward->sessionId = $unopened['sessionId'];

            return $reward;
        }, $this->rewards->unopened($user->getId()));
    }
}
