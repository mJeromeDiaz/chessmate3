<?php

declare(strict_types=1);

namespace App\State\EarlyAccess;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\EarlyAccess\CreateInvitationInput;
use App\ApiResource\EarlyAccess\InvitationKey;
use App\EarlyAccess\Invitation\InvitationException;
use App\EarlyAccess\Invitation\InvitationManager;
use App\Security\AuthenticatedUser;
use App\Security\RateLimit\RateLimitGuard;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * POST /admin/invitation-keys: 10 per minute per admin (each one sends an email). 422 when the
 * expiry is in the past or more than a year away.
 *
 * @implements ProcessorInterface<CreateInvitationInput, InvitationKey>
 */
final class CreateInvitationProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly InvitationManager $manager,
        private readonly AuthenticatedUser $authenticatedUser,
        private readonly RateLimitGuard $rateLimitGuard,
        private readonly RateLimiterFactory $earlyAccessInvitationWriteLimiter,
        private readonly ClockInterface $clock,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): InvitationKey
    {
        $admin = $this->authenticatedUser->get();
        $this->rateLimitGuard->consume($this->earlyAccessInvitationWriteLimiter, $admin->getId()->toRfc4122());

        try {
            [$invitation, $key] = $this->manager->create($admin, $data->email, $data->expiresAt, $data->neverExpires);
        } catch (InvitationException $exception) {
            throw new UnprocessableEntityHttpException($exception->getMessage());
        }

        return InvitationKey::from($invitation, $this->clock->now(), $key);
    }
}
