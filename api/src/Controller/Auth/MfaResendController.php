<?php

declare(strict_types=1);

namespace App\Controller\Auth;

use App\Dto\Auth\MfaResendRequest;
use App\Security\RateLimit\RateLimitGuard;
use App\Security\TwoFactor\Exception\MfaChallengeNotFoundException;
use App\Security\TwoFactor\Exception\MfaResendTooSoonException;
use App\Security\TwoFactor\MfaChallengeService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/auth/login/mfa')]
final class MfaResendController extends AbstractController
{
    public function __construct(
        private readonly MfaChallengeService $mfaChallengeService,
        private readonly RateLimitGuard $rateLimitGuard,
        #[Autowire(service: 'limiter.mfa_resend_ip')]
        private readonly RateLimiterFactory $resendIpLimiter,
        #[Autowire(service: 'limiter.mfa_resend_identifier')]
        private readonly RateLimiterFactory $resendIdentifierLimiter,
    ) {
    }

    #[Route('/resend', name: 'app_auth_mfa_resend', methods: ['POST'])]
    public function __invoke(#[MapRequestPayload] MfaResendRequest $payload, Request $request): JsonResponse
    {
        $this->rateLimitGuard->consume($this->resendIpLimiter, $request->getClientIp() ?? 'unknown');
        $this->rateLimitGuard->consume($this->resendIdentifierLimiter, hash('sha256', $payload->pendingToken));

        try {
            $this->mfaChallengeService->resend($payload->pendingToken);
        } catch (MfaChallengeNotFoundException) {
            throw new HttpException(Response::HTTP_UNAUTHORIZED, 'Invalid or expired session.');
        } catch (MfaResendTooSoonException) {
            throw new HttpException(Response::HTTP_TOO_MANY_REQUESTS, 'Please wait before requesting another code.');
        }

        return $this->json(['message' => 'A new code has been sent.'], Response::HTTP_ACCEPTED);
    }
}
