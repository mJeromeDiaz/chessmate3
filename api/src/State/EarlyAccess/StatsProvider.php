<?php

declare(strict_types=1);

namespace App\State\EarlyAccess;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\EarlyAccess\Stats;
use App\Dashboard\Period;
use App\EarlyAccess\Stats\CommunityActivity;
use App\EarlyAccess\Stats\InvitationFunnel;
use App\EarlyAccess\Stats\RatingDistribution;
use App\EarlyAccess\Stats\Signups;
use App\Security\AuthenticatedUser;
use App\Security\RateLimit\RateLimitGuard;
use Psr\Clock\ClockInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * GET /admin/stats?days= (admins only, rate limited per admin: admin_read).
 *
 * @implements ProviderInterface<Stats>
 */
final class StatsProvider implements ProviderInterface
{
    public function __construct(
        private readonly InvitationFunnel $invitationFunnel,
        private readonly Signups $signups,
        private readonly CommunityActivity $communityActivity,
        private readonly RatingDistribution $ratingDistribution,
        private readonly AuthenticatedUser $authenticatedUser,
        private readonly RateLimitGuard $rateLimitGuard,
        private readonly RateLimiterFactory $adminReadLimiter,
        private readonly ClockInterface $clock,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): Stats
    {
        $admin = $this->authenticatedUser->get();
        $this->rateLimitGuard->consume($this->adminReadLimiter, $admin->getId()->toRfc4122());
        $filters = \is_array($context['filters'] ?? null) ? $context['filters'] : [];
        $days = is_numeric($filters['days'] ?? null) ? (int) $filters['days'] : Stats::DEFAULT_DAYS;
        $now = $this->clock->now();
        $period = Period::last($days, $admin, $now);

        $view = new Stats();
        $view->timezone = $admin->getDateTimeZone()->getName();
        $view->from = $period->fromDate();
        $view->today = $period->todayDate();
        $view->invitations = $this->invitationFunnel->compute($period, $now);
        $view->signups = $this->signups->compute($period, $admin->getDateTimeZone());
        $view->activity = $this->communityActivity->compute($period, $now);
        $view->ratings = $this->ratingDistribution->compute();

        return $view;
    }
}
