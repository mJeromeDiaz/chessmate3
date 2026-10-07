<?php

declare(strict_types=1);

namespace App\State\Gamification;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Gamification\StreakNotice;
use App\Gamification\Streak\StreakNoticeReader;
use App\Security\AuthenticatedUser;
use App\Security\RateLimit\RateLimitGuard;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * GET /gamification/streak/notice: the current user's only, within the dashboard's read budget.
 *
 * @implements ProviderInterface<StreakNotice>
 */
final class StreakNoticeProvider implements ProviderInterface
{
    public function __construct(
        private readonly StreakNoticeReader $reader,
        private readonly AuthenticatedUser $authenticatedUser,
        private readonly RateLimitGuard $rateLimitGuard,
        private readonly RateLimiterFactory $dashboardReadLimiter,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): StreakNotice
    {
        $user = $this->authenticatedUser->get();
        $this->rateLimitGuard->consume($this->dashboardReadLimiter, $user->getId()->toRfc4122());
        $view = new StreakNotice();
        $notice = $this->reader->pending($user);
        if (null === $notice) {
            return $view;
        }
        $view->pending = true;
        $view->streak = $notice['streak'];
        $view->previousStreak = $notice['previousStreak'];
        $view->badge = $notice['badge'];
        $view->week = $notice['week'];
        $view->nextMilestone = $notice['nextMilestone'];
        $view->localDate = $notice['localDate'];

        return $view;
    }
}
