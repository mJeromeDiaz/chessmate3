<?php

declare(strict_types=1);

namespace App\Controller\EarlyAccess;

use App\EarlyAccess\Invitation\InvitationRedeemer;
use App\Security\RateLimit\RateLimitGuard;
use App\Security\Registration\RegistrationRefusedException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Lets the sign-up page tell a dead invitation link before the form is filled in
 * (docs/EARLY_ACCESS.md). Public, and nothing is logged: the sign-up itself checks the key again.
 * POST, so that the key stays out of URLs and access logs.
 */
final class InvitationCheckController extends AbstractController
{
    public function __construct(
        private readonly InvitationRedeemer $redeemer,
        private readonly RateLimitGuard $rateLimitGuard,
        #[Autowire(service: 'limiter.invitation_check_ip')]
        private readonly RateLimiterFactory $checkIpLimiter,
    ) {
    }

    #[Route('/api/auth/invitation/check', name: 'app_early_access_invitation_check', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $this->rateLimitGuard->consume($this->checkIpLimiter, $request->getClientIp() ?? 'unknown');

        try {
            $key = $request->toArray()['key'] ?? null;
            $invitation = $this->redeemer->inspect(\is_string($key) ? $key : null);
        } catch (RegistrationRefusedException $refusal) {
            return $this->json(['error' => $refusal->reason, 'message' => $refusal->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json(['expiresAt' => $invitation->getExpiresAt()?->format(\DATE_ATOM)]);
    }
}
