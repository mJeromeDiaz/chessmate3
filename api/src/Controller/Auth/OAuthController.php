<?php

declare(strict_types=1);

namespace App\Controller\Auth;

use App\Enum\AuditEventType;
use App\Enum\AuthProvider;
use App\Enum\OAuthFlowPurpose;
use App\Security\Audit\AuditLogger;
use App\Security\OAuth\Exception\OAuthFlowException;
use App\Security\OAuth\OAuthAccountService;
use App\Security\OAuth\OAuthFlowCookieFactory;
use App\Security\OAuth\OAuthFlowService;
use App\Security\RateLimit\RateLimitGuard;
use App\Security\Session\AuthenticatedSessionFactory;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Browser-facing OAuth endpoints: both are top-level navigations, not XHR calls.
 *
 * No token ever travels in a URL. A successful login sets the refresh-token cookie on the redirect
 * back to the SPA, which then gets its access token from /api/auth/refresh; the redirect itself
 * only carries an outcome code. No application 2FA: the provider's own authentication stands in
 * for it.
 */
#[Route('/api/auth/oauth/{provider}', requirements: ['provider' => 'google|lichess'])]
final class OAuthController extends AbstractController
{
    public function __construct(
        private readonly OAuthFlowService $flowService,
        private readonly OAuthAccountService $accountService,
        private readonly OAuthFlowCookieFactory $flowCookieFactory,
        private readonly AuthenticatedSessionFactory $sessionFactory,
        private readonly AuditLogger $auditLogger,
        private readonly RateLimitGuard $rateLimitGuard,
        #[Autowire(service: 'limiter.oauth_ip')]
        private readonly RateLimiterFactory $oauthIpLimiter,
        #[Autowire('%env(FRONTEND_URL)%')]
        private readonly string $frontendUrl,
    ) {
    }

    #[Route('/redirect', name: 'app_auth_oauth_redirect', methods: ['GET'])]
    public function redirectToProvider(AuthProvider $provider, Request $request): RedirectResponse
    {
        $this->rateLimitGuard->consume($this->oauthIpLimiter, $request->getClientIp() ?? 'unknown');

        $flow = $this->flowService->start($provider, OAuthFlowPurpose::Login);

        $response = $this->noStore(new RedirectResponse($flow->authorizationUrl));
        $response->headers->setCookie($flow->bindingCookie);

        return $response;
    }

    #[Route('/callback', name: 'app_auth_oauth_callback', methods: ['GET'])]
    public function callback(AuthProvider $provider, Request $request): RedirectResponse
    {
        $this->rateLimitGuard->consume($this->oauthIpLimiter, $request->getClientIp() ?? 'unknown');

        $user = null;
        $purpose = null;

        try {
            $completed = $this->flowService->complete($provider, $request);
            $purpose = $completed->flow->getPurpose();

            if (OAuthFlowPurpose::Link === $purpose) {
                $user = $completed->flow->getUser() ?? throw new OAuthFlowException(OAuthFlowException::INVALID_STATE);
                $this->accountService->link($user, $completed->identity);

                return $this->toSpa(['status' => 'success', 'mode' => 'link', 'provider' => $provider->value]);
            }

            [$user, $created] = $this->accountService->resolveLogin($completed->identity);
        } catch (OAuthFlowException|UniqueConstraintViolationException $exception) {
            $reason = $exception instanceof OAuthFlowException ? $exception->reason : OAuthFlowException::CONFLICT;
            $this->auditLogger->log(AuditEventType::OauthLoginFailure, $user, [
                'provider' => $provider->value,
                'purpose' => $purpose?->value,
                'reason' => $reason,
            ]);

            return $this->toSpa(['status' => 'error', 'mode' => ($purpose ?? OAuthFlowPurpose::Login)->value, 'provider' => $provider->value, 'reason' => $reason]);
        }

        $session = $this->sessionFactory->issueFor($user);
        $this->auditLogger->log(AuditEventType::OauthLoginSuccess, $user, ['provider' => $provider->value, 'new_account' => $created]);

        $response = $this->toSpa(['status' => 'success', 'mode' => 'login', 'provider' => $provider->value]);
        $response->headers->setCookie($session->refreshCookie);

        return $response;
    }

    /**
     * @param array<string, string> $params outcome codes only — never a token or personal data
     */
    private function toSpa(array $params): RedirectResponse
    {
        $response = $this->noStore(new RedirectResponse($this->frontendUrl.'/#/oauth/callback?'.http_build_query($params)));
        // The flow is over either way.
        $response->headers->setCookie($this->flowCookieFactory->clear());

        return $response;
    }

    private function noStore(RedirectResponse $response): RedirectResponse
    {
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
