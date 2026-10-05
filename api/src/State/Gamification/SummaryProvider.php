<?php

declare(strict_types=1);

namespace App\State\Gamification;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Gamification\Summary;
use App\ApiResource\Gamification\Trophies;
use App\ApiResource\Gamification\WeeklyQuest;
use App\Gamification\Quest\QuestService;
use App\Gamification\Summary\SummaryReader;
use App\Gamification\Trophy\TrophyEvaluator;
use App\Security\AuthenticatedUser;
use App\Security\RateLimit\RateLimitGuard;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * GET /gamification/summary, /gamification/trophies and /gamification/quest: the current user's
 * only, within the dashboard's read budget (dashboard_read).
 *
 * @implements ProviderInterface<Summary|Trophies|WeeklyQuest>
 */
final class SummaryProvider implements ProviderInterface
{
    public function __construct(
        private readonly SummaryReader $reader,
        private readonly TrophyEvaluator $trophies,
        private readonly QuestService $quests,
        private readonly AuthenticatedUser $authenticatedUser,
        private readonly RateLimitGuard $rateLimitGuard,
        private readonly RateLimiterFactory $dashboardReadLimiter,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): Summary|Trophies|WeeklyQuest
    {
        $user = $this->authenticatedUser->get();
        $this->rateLimitGuard->consume($this->dashboardReadLimiter, $user->getId()->toRfc4122());
        if (Trophies::class === $operation->getClass()) {
            $trophies = new Trophies();
            $trophies->trophies = $this->trophies->trophies($user);

            return $trophies;
        }
        if (WeeklyQuest::class === $operation->getClass()) {
            $data = $this->quests->current($user);
            $quest = new WeeklyQuest();
            $quest->id = $data['id'];
            $quest->template = $data['template'];
            $quest->theme = $data['theme'];
            $quest->module = $data['module'];
            $quest->goal = $data['goal'];
            $quest->current = $data['current'];
            $quest->reward = $data['reward'];
            $quest->completed = $data['completed'];
            $quest->completedAt = $data['completedAt'];
            $quest->weekStart = $data['weekStart'];
            $quest->weekEnd = $data['weekEnd'];

            return $quest;
        }
        $data = $this->reader->read($user);

        $summary = new Summary();
        $summary->xp = $data['xp'];
        $summary->level = $data['level'];
        $summary->xpInLevel = $data['xpInLevel'];
        $summary->xpForNext = $data['xpForNext'];
        $summary->rank = $data['rank'];
        $summary->nextRank = $data['nextRank'];
        $summary->modules = $data['modules'];
        $summary->streak = $data['streak'];
        $summary->today = $data['today'];

        return $summary;
    }
}
