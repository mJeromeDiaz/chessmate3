<?php

declare(strict_types=1);

namespace App\State\Gamification;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Gamification\Streak\StreakNoticeReader;
use App\Security\AuthenticatedUser;
use App\Security\RateLimit\RateLimitGuard;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * POST /gamification/streak/notice/acknowledgement.
 *
 * @implements ProcessorInterface<mixed, null>
 */
final class StreakNoticeProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly StreakNoticeReader $reader,
        private readonly AuthenticatedUser $authenticatedUser,
        private readonly RateLimitGuard $rateLimitGuard,
        private readonly RateLimiterFactory $dashboardReadLimiter,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $user = $this->authenticatedUser->get();
        $this->rateLimitGuard->consume($this->dashboardReadLimiter, $user->getId()->toRfc4122());
        $this->reader->acknowledge($user);

        return null;
    }
}
