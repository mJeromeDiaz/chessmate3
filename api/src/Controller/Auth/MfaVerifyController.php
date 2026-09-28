<?php

declare(strict_types=1);

namespace App\Controller\Auth;

use App\Dto\Auth\MfaVerifyRequest;
use App\Enum\AuditEventType;
use App\Security\Audit\AuditLogger;
use App\Security\RateLimit\RateLimitGuard;
use App\Security\Session\AuthenticatedSessionFactory;
use App\Security\TrustedDevice\TrustedDeviceCookieFactory;
use App\Security\TrustedDevice\TrustedDeviceService;
use App\Security\TwoFactor\Exception\MfaChallengeNotFoundException;
use App\Security\TwoFactor\Exception\MfaCodeInvalidException;
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

/**
 * Step 2 of email + password login: the 6-digit code. No JWT can be obtained through any other
 * path — only a successful call here (or a trusted-device bypass in
 * {@see \App\Controller\Auth\LoginController}) ever reaches {@see AuthenticatedSessionFactory}.
 */
#[Route('/api/auth/login/mfa')]
final class MfaVerifyController extends AbstractController
{
    public function __construct(
        private readonly MfaChallengeService $mfaChallengeService,
        private readonly AuthenticatedSessionFactory $sessionFactory,
        private readonly TrustedDeviceService $trustedDeviceService,
        private readonly TrustedDeviceCookieFactory $trustedDeviceCookieFactory,
        private readonly AuditLogger $auditLogger,
        private readonly RateLimitGuard $rateLimitGuard,
        #[Autowire(service: 'limiter.mfa_verify_ip')]
        private readonly RateLimiterFactory $verifyIpLimiter,
        #[Autowire(service: 'limiter.mfa_verify_identifier')]
        private readonly RateLimiterFactory $verifyIdentifierLimiter,
    ) {
    }

    #[Route('/verify', name: 'app_auth_mfa_verify', methods: ['POST'])]
    public function __invoke(#[MapRequestPayload] MfaVerifyRequest $payload, Request $request): JsonResponse
    {
        $this->rateLimitGuard->consume($this->verifyIpLimiter, $request->getClientIp() ?? 'unknown');
        $this->rateLimitGuard->consume($this->verifyIdentifierLimiter, hash('sha256', $payload->pendingToken));

        try {
            $user = $this->mfaChallengeService->verify($payload->pendingToken, $payload->code);
        } catch (MfaChallengeNotFoundException|MfaCodeInvalidException) {
            throw new HttpException(Response::HTTP_UNAUTHORIZED, 'Invalid or expired code.');
        }

        $session = $this->sessionFactory->issueFor($user);

        $auditMetadata = [];
        $response = $this->json(['accessToken' => $session->accessToken]);
        $response->headers->setCookie($session->refreshCookie);

        if ($payload->trustDevice) {
            $trustedDevice = $this->trustedDeviceService->issue($user, $request->getClientIp(), $request->headers->get('User-Agent'));
            $response->headers->setCookie($this->trustedDeviceCookieFactory->create($trustedDevice));
            $auditMetadata['trusted_device_added'] = true;
        }

        $this->auditLogger->log(AuditEventType::LoginSuccess, $user, $auditMetadata);

        return $response;
    }
}
