<?php

declare(strict_types=1);

namespace App\State\EarlyAccess;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\EarlyAccess\Player;
use App\ApiResource\EarlyAccess\SuspendInput;
use App\EarlyAccess\Player\Suspension;
use App\EarlyAccess\Player\SuspensionException;
use App\EarlyAccess\Stats\PlayerFacts;
use App\Security\AuthenticatedUser;
use App\Security\RateLimit\RateLimitGuard;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Uid\Uuid;

/**
 * POST /admin/users/{id}/suspend {reason?} and POST /admin/users/{id}/unsuspend (both idempotent,
 * rate limited per admin: admin_write). 404 for an unknown account, 409 for the admin's own account
 * or another admin's.
 *
 * @implements ProcessorInterface<SuspendInput|null, Player>
 */
final class PlayerActionProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly Suspension $suspension,
        private readonly PlayerFacts $playerFacts,
        private readonly AuthenticatedUser $authenticatedUser,
        private readonly RateLimitGuard $rateLimitGuard,
        private readonly RateLimiterFactory $adminWriteLimiter,
        private readonly ClockInterface $clock,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Player
    {
        $admin = $this->authenticatedUser->get();
        $this->rateLimitGuard->consume($this->adminWriteLimiter, $admin->getId()->toRfc4122());
        $id = $uriVariables['id'] ?? null;
        if (!\is_string($id) || !Uuid::isValid($id)) {
            throw new NotFoundHttpException('Account not found.');
        }

        try {
            $user = 'early_access_player_suspend' === $operation->getName()
                ? $this->suspension->suspend($admin, Uuid::fromString($id), $data instanceof SuspendInput ? $data->reason : null)
                : $this->suspension->lift($admin, Uuid::fromString($id));
        } catch (SuspensionException $exception) {
            throw SuspensionException::NOT_FOUND === $exception->reason
                ? new NotFoundHttpException($exception->getMessage())
                : new ConflictHttpException($exception->getMessage());
        }

        $now = $this->clock->now();

        return Player::from($user, $this->playerFacts->forUsers([$user], $now)[$user->getId()->toRfc4122()], $now);
    }
}
