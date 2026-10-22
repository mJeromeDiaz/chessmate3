<?php

declare(strict_types=1);

namespace App\State\Shop;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Shop\DailyReward;
use App\Security\AuthenticatedUser;
use App\Shop\Reward\DailyRewarder;

/**
 * GET /shop/daily-rewards/unopened: the daily chests the player has not seen, oldest first
 * (docs/SHOP.md, § 4 ter), shown after the streak's celebration or on the dashboard.
 *
 * @implements ProviderInterface<DailyReward>
 */
final class UnopenedDailyRewardsProvider implements ProviderInterface
{
    public function __construct(
        private readonly DailyRewarder $rewards,
        private readonly AuthenticatedUser $authenticatedUser,
    ) {
    }

    /**
     * @return list<DailyReward>
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $user = $this->authenticatedUser->get();
        $balance = $this->rewards->balance($user->getId());

        return array_map(static fn (array $view): DailyReward => DailyReward::of($view, $balance), $this->rewards->unopened($user->getId()));
    }
}
