<?php

declare(strict_types=1);

namespace App\State\EarlyAccess;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\EarlyAccess\AccessRequest;
use App\EarlyAccess\Invitation\InvitationException;
use App\EarlyAccess\Invitation\InvitationManager;
use App\Repository\EarlyAccess\AccessRequestRepository;
use App\Security\AuthenticatedUser;
use App\Security\RateLimit\RateLimitGuard;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Uid\Uuid;

/**
 * POST /admin/access-requests/{id}/invite (an invitation for its address, counted with the other
 * invitations sent: early_access_invitation_write; 409 if already invited) and
 * DELETE /admin/access-requests/{id} (204; 404 if unknown).
 *
 * @implements ProcessorInterface<null, AccessRequest|null>
 */
final class AccessRequestProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly AccessRequestRepository $requests,
        private readonly InvitationManager $invitations,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuthenticatedUser $authenticatedUser,
        private readonly RateLimitGuard $rateLimitGuard,
        private readonly RateLimiterFactory $earlyAccessInvitationWriteLimiter,
        private readonly ClockInterface $clock,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ?AccessRequest
    {
        $admin = $this->authenticatedUser->get();
        $id = $uriVariables['id'] ?? null;
        $request = \is_string($id) && Uuid::isValid($id) ? $this->requests->find(Uuid::fromString($id)) : null;
        if (null === $request) {
            throw new NotFoundHttpException('Request not found.');
        }

        if ('early_access_request_delete' === $operation->getName()) {
            $this->entityManager->remove($request);
            $this->entityManager->flush();

            return null;
        }

        $this->rateLimitGuard->consume($this->earlyAccessInvitationWriteLimiter, $admin->getId()->toRfc4122());
        try {
            [, $key] = $this->invitations->createFromRequest($admin, $request);
        } catch (InvitationException $exception) {
            throw new ConflictHttpException($exception->getMessage());
        }
        $hasAccount = [] !== $this->requests->findEmailsWithAccount([$request->getEmail()]);

        return AccessRequest::from($request, $this->clock->now(), $hasAccount, $key);
    }
}
