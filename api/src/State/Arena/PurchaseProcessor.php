<?php

declare(strict_types=1);

namespace App\State\Arena;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Arena\Overview;
use App\Arena\Game\GameRefusal;
use App\Arena\Shop\BossShop;
use App\Security\AuthenticatedUser;
use App\Security\RateLimit\RateLimitGuard;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * POST /arena/bosses/{boss}/purchase (docs/ARENA.md, § 6 bis): a refusal becomes its status, its
 * value as the message; the arena once bought.
 *
 * @implements ProcessorInterface<null, Overview>
 */
final class PurchaseProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly BossShop $shop,
        private readonly OverviewProvider $overview,
        private readonly AuthenticatedUser $authenticatedUser,
        private readonly RateLimitGuard $rateLimitGuard,
        private readonly RateLimiterFactory $arenaGameWriteLimiter,
        private readonly ClockInterface $clock,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Overview
    {
        $user = $this->authenticatedUser->get();
        $this->rateLimitGuard->consume($this->arenaGameWriteLimiter, $user->getId()->toRfc4122());
        $boss = $uriVariables['boss'] ?? null;
        $refusal = $this->shop->buy($user, \is_string($boss) ? $boss : '', $this->clock->now());
        if (null !== $refusal) {
            throw GameRefusal::UnknownBoss === $refusal ? new NotFoundHttpException($refusal->value) : new ConflictHttpException($refusal->value);
        }

        return $this->overview->provide($operation, $uriVariables, $context);
    }
}
