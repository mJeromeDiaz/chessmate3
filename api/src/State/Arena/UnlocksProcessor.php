<?php

declare(strict_types=1);

namespace App\State\Arena;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Arena\AnnounceInput;
use App\ApiResource\Arena\Unlocks as UnlocksView;
use App\Arena\Roster\ArenaReader;
use App\Arena\Unlock\Unlocks;
use App\Security\AuthenticatedUser;
use App\Security\RateLimit\RateLimitGuard;
use Psr\Clock\ClockInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * POST /arena/unlocks/announce: the client showed the arrival of these bosses (idempotent); the
 * bosses still waiting.
 *
 * @implements ProcessorInterface<AnnounceInput, UnlocksView>
 */
final class UnlocksProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly ArenaReader $reader,
        private readonly Unlocks $unlocks,
        private readonly AuthenticatedUser $authenticatedUser,
        private readonly RateLimitGuard $rateLimitGuard,
        private readonly RateLimiterFactoryInterface $arenaGameWriteLimiter,
        private readonly ClockInterface $clock,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): UnlocksView
    {
        $user = $this->authenticatedUser->get();
        $this->rateLimitGuard->consume($this->arenaGameWriteLimiter, $user->getId()->toRfc4122());
        $now = $this->clock->now();
        $this->unlocks->announce($user, $data->bosses, $now);
        $arena = $this->reader->read($user);

        return UnlocksProvider::view($arena['bosses'], $this->unlocks->sync($user, $now, $arena['statuses']));
    }
}
