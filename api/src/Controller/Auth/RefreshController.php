<?php

declare(strict_types=1);

namespace App\Controller\Auth;

use App\Security\RateLimit\RateLimitGuard;
use App\Security\RefreshToken\Exception\RefreshTokenException;
use App\Security\RefreshToken\Exception\RefreshTokenReuseDetectedException;
use App\Security\RefreshToken\RefreshTokenCookieFactory;
use App\Security\RefreshToken\RefreshTokenService;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Rotates the refresh token cookie for a new access token.
 *
 * CSRF: this is the one endpoint a cross-site request could target using just the ambient cookie
 * (no other API route reveals anything on a cookie-only, credential-less request). The
 * "X-Refresh-Request" header requirement is a simple, effective defense — a cross-site `<form>` or
 * plain navigation cannot set custom headers, and the browser's CORS preflight for a cross-origin
 * fetch that does set it is governed by the strict, explicit-origin CORS policy in
 * config/packages/nelmio_cors.yaml, not by this header itself.
 */
#[Route('/api/auth')]
final class RefreshController extends AbstractController
{
    public function __construct(
        private readonly RefreshTokenService $refreshTokenService,
        private readonly RefreshTokenCookieFactory $cookieFactory,
        private readonly JWTTokenManagerInterface $jwtManager,
        private readonly RateLimitGuard $rateLimitGuard,
        #[Autowire(service: 'limiter.refresh_ip')]
        private readonly RateLimiterFactory $refreshIpLimiter,
        #[Autowire(service: 'limiter.refresh_identifier')]
        private readonly RateLimiterFactory $refreshIdentifierLimiter,
    ) {
    }

    #[Route('/refresh', name: 'app_auth_refresh', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $this->rateLimitGuard->consume($this->refreshIpLimiter, $request->getClientIp() ?? 'unknown');

        if ('1' !== $request->headers->get('X-Refresh-Request')) {
            throw new HttpException(Response::HTTP_FORBIDDEN, 'Missing refresh request header.');
        }

        $presentedToken = $this->cookieFactory->readFrom($request);

        if (null === $presentedToken) {
            throw new HttpException(Response::HTTP_UNAUTHORIZED, 'No active session.');
        }

        $this->rateLimitGuard->consume($this->refreshIdentifierLimiter, hash('sha256', $presentedToken));

        try {
            $result = $this->refreshTokenService->rotate($presentedToken);
        } catch (RefreshTokenReuseDetectedException) {
            $response = $this->json(['message' => 'Session revoked.'], Response::HTTP_UNAUTHORIZED);
            $response->headers->setCookie($this->cookieFactory->clear());

            return $response;
        } catch (RefreshTokenException) {
            $response = $this->json(['message' => 'Invalid or expired session.'], Response::HTTP_UNAUTHORIZED);
            $response->headers->setCookie($this->cookieFactory->clear());

            return $response;
        }

        $accessToken = $this->jwtManager->create($result->user);

        $response = $this->json(['accessToken' => $accessToken]);
        $response->headers->setCookie($this->cookieFactory->create($result->refreshToken));

        return $response;
    }
}
