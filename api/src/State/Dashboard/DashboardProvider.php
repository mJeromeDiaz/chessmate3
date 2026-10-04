<?php

declare(strict_types=1);

namespace App\State\Dashboard;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Dashboard\Activity;
use App\ApiResource\Dashboard\LichessRatingHistory;
use App\ApiResource\Dashboard\RatingHistory;
use App\Dashboard\Activity\ActivityCalendar;
use App\Dashboard\Lichess\RatingHistoryClient;
use App\Dashboard\Period;
use App\Dashboard\Rating\RatingHistory as History;
use App\Entity\User;
use App\Enum\AuthProvider;
use App\Repertoire\Lichess\LichessUnavailableException;
use App\Repertoire\Lichess\TokenResolver;
use App\Security\AuthenticatedUser;
use App\Security\RateLimit\RateLimitGuard;
use App\State\Repertoire\LichessUnavailableHttpException;
use Psr\Clock\ClockInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * GET /dashboard/activity, /dashboard/rating-history and /dashboard/lichess-rating-history: the
 * current user's data only, rate limited per user (dashboard_read).
 *
 * @implements ProviderInterface<Activity|RatingHistory|LichessRatingHistory>
 */
final class DashboardProvider implements ProviderInterface
{
    public function __construct(
        private readonly ActivityCalendar $calendar,
        private readonly History $ratingHistory,
        private readonly RatingHistoryClient $lichess,
        private readonly TokenResolver $tokens,
        private readonly AuthenticatedUser $authenticatedUser,
        private readonly RateLimitGuard $rateLimitGuard,
        private readonly RateLimiterFactory $dashboardReadLimiter,
        private readonly ClockInterface $clock,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): Activity|RatingHistory|LichessRatingHistory
    {
        $user = $this->authenticatedUser->get();
        $this->rateLimitGuard->consume($this->dashboardReadLimiter, $user->getId()->toRfc4122());
        $filters = \is_array($context['filters'] ?? null) ? $context['filters'] : [];
        $days = static fn (int $default): int => is_numeric($filters['days'] ?? null) ? (int) $filters['days'] : $default;

        return match ($operation->getClass()) {
            Activity::class => $this->activity($user, Period::last($days(Activity::DEFAULT_DAYS), $user, $this->clock->now())),
            RatingHistory::class => $this->rating($user, Period::last($days(RatingHistory::DEFAULT_DAYS), $user, $this->clock->now())),
            LichessRatingHistory::class => $this->lichess($user, Period::last($days(RatingHistory::DEFAULT_DAYS), $user, $this->clock->now())),
            default => throw new \LogicException('Unexpected resource.'),
        };
    }

    private function activity(User $user, Period $period): Activity
    {
        $view = new Activity();
        $view->timezone = $user->getDateTimeZone()->getName();
        $view->from = $period->fromDate();
        $view->today = $period->todayDate();
        $view->days = $this->calendar->days($user, $period);
        $view->totals = $this->calendar->totals($user);

        return $view;
    }

    private function rating(User $user, Period $period): RatingHistory
    {
        $view = new RatingHistory();
        $view->from = $period->fromDate();
        $view->today = $period->todayDate();
        $view->points = $this->ratingHistory->points($user, $period);

        return $view;
    }

    private function lichess(User $user, Period $period): LichessRatingHistory
    {
        $view = new LichessRatingHistory();
        $view->from = $period->fromDate();
        $view->today = $period->todayDate();
        foreach ($user->getAuthIdentities() as $identity) {
            if (AuthProvider::Lichess === $identity->getProvider()) {
                $view->linked = true;
                $view->username = $identity->getProviderUserId();
                break;
            }
        }
        if (null === $view->username) {
            return $view;
        }

        try {
            $history = $this->lichess->history($view->username, $this->tokens->userToken($user));
        } catch (LichessUnavailableException $e) {
            throw LichessUnavailableHttpException::from($e);
        }
        foreach ($history as $perf => $points) {
            $view->perfs[$perf] = RatingHistoryClient::window($points, $view->from, $view->today);
        }

        return $view;
    }
}
