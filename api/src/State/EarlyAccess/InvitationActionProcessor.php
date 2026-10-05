<?php

declare(strict_types=1);

namespace App\State\EarlyAccess;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\EarlyAccess\InvitationKey;
use App\EarlyAccess\Invitation\InvitationException;
use App\EarlyAccess\Invitation\InvitationManager;
use App\Security\AuthenticatedUser;
use App\Security\RateLimit\RateLimitGuard;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * POST /admin/invitation-keys/{id}/resend (a new key, counted with the creations) and
 * DELETE /admin/invitation-keys/{id} (revocation, idempotent). 409 on a used invitation, and on a
 * revoked one for a resend.
 *
 * @implements ProcessorInterface<null, InvitationKey|null>
 */
final class InvitationActionProcessor implements ProcessorInterface
{
    use InvitationIdTrait;

    public function __construct(
        private readonly InvitationManager $manager,
        private readonly AuthenticatedUser $authenticatedUser,
        private readonly RateLimitGuard $rateLimitGuard,
        private readonly RateLimiterFactory $earlyAccessInvitationWriteLimiter,
        private readonly ClockInterface $clock,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ?InvitationKey
    {
        $admin = $this->authenticatedUser->get();
        $id = self::invitationId($uriVariables);

        try {
            if ('early_access_invitation_resend' === $operation->getName()) {
                $this->rateLimitGuard->consume($this->earlyAccessInvitationWriteLimiter, $admin->getId()->toRfc4122());
                [$invitation, $key] = $this->manager->resend($admin, $id);

                return InvitationKey::from($invitation, $this->clock->now(), $key);
            }
            $this->manager->revoke($admin, $id);

            return null;
        } catch (InvitationException $exception) {
            throw InvitationException::NOT_FOUND === $exception->reason
                ? new NotFoundHttpException($exception->getMessage())
                : new ConflictHttpException($exception->getMessage());
        }
    }
}
