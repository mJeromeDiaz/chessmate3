<?php

declare(strict_types=1);

namespace App\Controller\Auth;

use App\Dto\Auth\ChangePasswordRequest;
use App\Entity\User;
use App\Enum\AuditEventType;
use App\Security\Audit\AuditLogger;
use App\Security\Password\PasswordChanger;
use App\Security\RateLimit\RateLimitGuard;
use App\Security\Session\AuthenticatedSessionFactory;
use App\Security\TrustedDevice\TrustedDeviceCookieFactory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Password change from the profile, current password required.
 *
 * Every session is revoked — the caller's own included — and the caller then gets a brand new
 * session in a new refresh-token family: someone who had stolen a token from this very session is
 * cut off too, while the user stays signed in. Adding a first password to an OAuth-only account is
 * a different endpoint (profile), since there is no current password to check.
 */
#[Route('/api/auth')]
final class ChangePasswordController extends AbstractController
{
    public function __construct(
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly PasswordChanger $passwordChanger,
        private readonly AuthenticatedSessionFactory $sessionFactory,
        private readonly TrustedDeviceCookieFactory $trustedDeviceCookieFactory,
        private readonly AuditLogger $auditLogger,
        private readonly RateLimitGuard $rateLimitGuard,
        #[Autowire(service: 'limiter.password_change_ip')]
        private readonly RateLimiterFactory $passwordChangeIpLimiter,
        #[Autowire(service: 'limiter.password_change_identifier')]
        private readonly RateLimiterFactory $passwordChangeIdentifierLimiter,
    ) {
    }

    #[Route('/password/change', name: 'app_auth_password_change', methods: ['POST'])]
    public function __invoke(#[MapRequestPayload] ChangePasswordRequest $payload, #[CurrentUser] User $user, Request $request): JsonResponse
    {
        $this->rateLimitGuard->consume($this->passwordChangeIpLimiter, $request->getClientIp() ?? 'unknown');
        $this->rateLimitGuard->consume($this->passwordChangeIdentifierLimiter, $user->getUserIdentifier());

        if (!$user->hasPassword()) {
            throw new HttpException(Response::HTTP_CONFLICT, 'This account has no password yet.');
        }

        if (!$this->passwordHasher->isPasswordValid($user, $payload->currentPassword)) {
            $this->auditLogger->log(AuditEventType::PasswordChangeFailed, $user);

            throw new HttpException(Response::HTTP_BAD_REQUEST, 'Current password is incorrect.');
        }

        $this->passwordChanger->change($user, $payload->newPassword);

        $session = $this->sessionFactory->issueFor($user);

        $response = $this->json(['accessToken' => $session->accessToken]);
        $response->headers->setCookie($session->refreshCookie);
        // The device's trust was revoked server-side; drop the now-useless cookie too.
        $response->headers->setCookie($this->trustedDeviceCookieFactory->clear());

        return $response;
    }
}
