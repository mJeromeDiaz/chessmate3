<?php

declare(strict_types=1);

namespace App\Controller\Auth;

use App\Enum\AuditEventType;
use App\Enum\AuthProvider;
use App\Enum\OAuthFlowPurpose;
use App\Security\Account\AccountSuspendedException;
use App\Security\Audit\AuditLogger;
use App\Security\OAuth\Exception\OAuthFlowException;
use App\Security\OAuth\OAuthAccountService;
use App\Security\OAuth\OAuthFlowCookieFactory;
use App\Security\OAuth\OAuthFlowService;
use App\Security\RateLimit\RateLimitGuard;
use App\Security\Registration\RegistrationGateInterface;
use App\Security\Registration\RegistrationRefusedException;
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
 *
 * A new account needs an invitation key ({@see RegistrationGateInterface}): the sign-up page
 * starts the flow with a form POST carrying it, so the key never appears in a URL (nor in an
 * access log). It is checked before going to the provider, and spent when the account is opened.
 * A plain GET only signs existing accounts in.
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
        private readonly RegistrationGateInterface $registrationGate,
        #[Autowire(service: 'limiter.oauth_ip')]
        private readonly RateLimiterFactory $oauthIpLimiter,
        #[Autowire('%env(FRONTEND_URL)%')]
        private readonly string $frontendUrl,
    ) {
    }

    #[Route('/redirect', name: 'app_auth_oauth_redirect', methods: ['GET', 'POST'])]
    public function redirectToProvider(AuthProvider $provider, Request $request): RedirectResponse
    {
        $this->rateLimitGuard->consume($this->oauthIpLimiter, $request->getClientIp() ?? 'unknown');

        $ticket = null;
        if ($request->isMethod('POST')) {
            $key = $request->request->get('invitationKey');
            try {
                $ticket = $this->registrationGate->admit(\is_string($key) ? $key : null);
            } catch (RegistrationRefusedException $refusal) {
                return $this->toSpa(['status' => 'error', 'mode' => OAuthFlowPurpose::Login->value, 'provider' => $provider->value, 'reason' => $refusal->reason]);
            }
        }

        $flow = $this->flowService->start($provider, OAuthFlowPurpose::Login, registrationTicket: $ticket);

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

            if (OAuthFlowPurpose::Grant === $purpose) {
                $user = $completed->flow->getUser() ?? throw new OAuthFlowException(OAuthFlowException::INVALID_STATE);
                $this->accountService->grant($user, $completed->identity, OAuthFlowPurpose::grantScopes($provider));

                return $this->toSpa(['status' => 'success', 'mode' => 'grant', 'provider' => $provider->value]);
            }

            if (OAuthFlowPurpose::Link === $purpose) {
                $user = $completed->flow->getUser() ?? throw new OAuthFlowException(OAuthFlowException::INVALID_STATE);
                $this->accountService->link($user, $completed->identity);

                return $this->toSpa(['status' => 'success', 'mode' => 'link', 'provider' => $provider->value]);
            }

            [$user, $created] = $this->accountService->resolveLogin($completed->identity, $completed->flow->getRegistrationTicket());
        } catch (OAuthFlowException|UniqueConstraintViolationException $exception) {
            $reason = $exception instanceof OAuthFlowException ? $exception->reason : OAuthFlowException::CONFLICT;
            $this->auditLogger->log(AuditEventType::OauthLoginFailure, $user, [
                'provider' => $provider->value,
                'purpose' => $purpose?->value,
                'reason' => $reason,
            ]);

            return $this->toSpa(['status' => 'error', 'mode' => ($purpose ?? OAuthFlowPurpose::Login)->value, 'provider' => $provider->value, 'reason' => $reason]);
        }

        // The provider proved who this is: telling the account is suspended leaks nothing.
        if ($user->isSuspended()) {
            $this->auditLogger->log(AuditEventType::OauthLoginFailure, $user, ['provider' => $provider->value, 'purpose' => OAuthFlowPurpose::Login->value, 'reason' => AccountSuspendedException::REASON]);

            return $this->toSpa(['status' => 'error', 'mode' => OAuthFlowPurpose::Login->value, 'provider' => $provider->value, 'reason' => AccountSuspendedException::REASON]);
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
