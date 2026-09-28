<?php

declare(strict_types=1);

namespace App\Controller\Auth;

use App\Dto\Auth\LoginRequest;
use App\Entity\User;
use App\Enum\AuditEventType;
use App\Repository\UserRepository;
use App\Security\Audit\AuditLogger;
use App\Security\RateLimit\RateLimitGuard;
use App\Security\Session\AuthenticatedSessionFactory;
use App\Security\TrustedDevice\TrustedDeviceCookieFactory;
use App\Security\TrustedDevice\TrustedDeviceService;
use App\Security\TwoFactor\MfaChallengeService;
use App\Security\TwoFactor\MfaFailureLimiter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Step 1 of email + password login.
 *
 * On valid credentials for a verified account, this either completes the login immediately (a
 * trusted device cookie for this exact user is present and still active) or starts the mandatory
 * email 2FA challenge — it never issues a JWT itself in the latter case, only a short-lived
 * "mfa_pending" token the client resubmits to {@see \App\Controller\Auth\MfaVerifyController}.
 *
 * Invalid-credentials responses are identical whether the email doesn't exist, has no password set
 * (an OAuth-only account), or the password is simply wrong — and a password hash is always computed
 * against *some* value so the response time doesn't leak which case it was.
 */
#[Route('/api/auth')]
final class LoginController extends AbstractController
{
    private const DUMMY_HASH = '$argon2id$v=19$m=65536,t=4,p=1$TzN5SmpRZndHbFNJWXE0UQ$qd9nzN9pUlFObtu6B6B6OQvPy3c+CjdVOyon6CFbaH4';

    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly MfaFailureLimiter $mfaFailureLimiter,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly AuthenticatedSessionFactory $sessionFactory,
        private readonly TrustedDeviceService $trustedDeviceService,
        private readonly TrustedDeviceCookieFactory $trustedDeviceCookieFactory,
        private readonly MfaChallengeService $mfaChallengeService,
        private readonly AuditLogger $auditLogger,
        private readonly RateLimitGuard $rateLimitGuard,
        #[Autowire(service: 'limiter.login_ip')]
        private readonly RateLimiterFactory $loginIpLimiter,
        #[Autowire(service: 'limiter.login_identifier')]
        private readonly RateLimiterFactory $loginIdentifierLimiter,
    ) {
    }

    #[Route('/login', name: 'app_auth_login', methods: ['POST'])]
    public function __invoke(#[MapRequestPayload] LoginRequest $payload, Request $request): JsonResponse
    {
        $email = strtolower(trim($payload->email));

        $this->rateLimitGuard->consume($this->loginIpLimiter, $request->getClientIp() ?? 'unknown');
        $this->rateLimitGuard->consume($this->loginIdentifierLimiter, $email);

        $user = $this->userRepository->findOneByEmail($email);
        $hasPassword = $user?->hasPassword() ?? false;

        $dummy = new User();
        $dummy->setPassword(self::DUMMY_HASH);

        $passwordValid = $this->passwordHasher->isPasswordValid($hasPassword ? $user : $dummy, $payload->password);

        if (null === $user || !$hasPassword || !$passwordValid) {
            $this->auditLogger->log(AuditEventType::LoginFailure, $user, ['email_attempted' => $email]);

            throw new HttpException(Response::HTTP_UNAUTHORIZED, 'Invalid credentials.');
        }

        if (!$user->isEmailVerified()) {
            throw new HttpException(Response::HTTP_FORBIDDEN, 'Email address not verified.');
        }

        $trustedDeviceToken = $this->trustedDeviceCookieFactory->readFrom($request);
        $trustedDevice = null !== $trustedDeviceToken
            ? $this->trustedDeviceService->findActiveForUser($user, $trustedDeviceToken)
            : null;

        if (null !== $trustedDevice) {
            $this->trustedDeviceService->markUsed($trustedDevice);

            return $this->issueSession($user, ['trusted_device' => true]);
        }

        // Too many wrong codes lately (see MfaFailureLimiter): no new code for now.
        if ($this->mfaFailureLimiter->isBlocked($user)) {
            $this->auditLogger->log(AuditEventType::LoginFailure, $user, ['reason' => 'mfa_failures_exceeded']);

            throw new TooManyRequestsHttpException(null, 'Too many requests. Please try again later.');
        }

        $challenge = $this->mfaChallengeService->create($user, $request->getClientIp(), $request->headers->get('User-Agent'));

        return $this->json([
            'mfaPendingToken' => $challenge->pendingToken,
            'method' => $challenge->method,
            'expiresAt' => $challenge->expiresAt->format(\DATE_ATOM),
        ], Response::HTTP_ACCEPTED);
    }

    /**
     * @param array<string, mixed> $auditMetadata
     */
    private function issueSession(User $user, array $auditMetadata = []): JsonResponse
    {
        $session = $this->sessionFactory->issueFor($user);

        $this->auditLogger->log(AuditEventType::LoginSuccess, $user, $auditMetadata);

        $response = $this->json(['accessToken' => $session->accessToken]);
        $response->headers->setCookie($session->refreshCookie);

        return $response;
    }
}
