<?php

declare(strict_types=1);

namespace App\Controller\Profile;

use App\Entity\User;
use App\Enum\AuthProvider;
use App\Enum\OAuthFlowPurpose;
use App\Security\OAuth\OAuthFlowService;
use App\Security\RateLimit\RateLimitGuard;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Starts linking a provider account to the signed-in user — the only way an identity ever gets
 * attached to an existing account.
 *
 * Called with fetch (it needs the access token), so it can't redirect: it returns the provider URL
 * for the SPA to navigate to, and sets the flow cookie that ties the callback to this user. The
 * callback comes back to {@see \App\Controller\Auth\OAuthController::callback()}.
 */
#[Route('/api/profile/identities')]
final class IdentityLinkController extends AbstractController
{
    public function __construct(
        private readonly OAuthFlowService $flowService,
        private readonly RateLimitGuard $rateLimitGuard,
        #[Autowire(service: 'limiter.oauth_ip')]
        private readonly RateLimiterFactory $oauthIpLimiter,
    ) {
    }

    #[Route('/{provider}/link', name: 'app_profile_identity_link', requirements: ['provider' => 'google|lichess'], methods: ['POST'])]
    public function __invoke(AuthProvider $provider, #[CurrentUser] User $user, Request $request): JsonResponse
    {
        $this->rateLimitGuard->consume($this->oauthIpLimiter, $request->getClientIp() ?? 'unknown');

        $flow = $this->flowService->start($provider, OAuthFlowPurpose::Link, $user);

        $response = $this->json(['authorizationUrl' => $flow->authorizationUrl]);
        $response->headers->setCookie($flow->bindingCookie);

        return $response;
    }
}
