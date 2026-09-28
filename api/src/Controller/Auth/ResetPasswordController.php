<?php

declare(strict_types=1);

namespace App\Controller\Auth;

use App\Dto\Auth\ResetPasswordRequest;
use App\Entity\User;
use App\Enum\AuditEventType;
use App\Repository\ResetPasswordRequestRepository;
use App\Security\Password\PasswordChanger;
use App\Security\RateLimit\RateLimitGuard;
use App\Security\RefreshToken\RefreshTokenCookieFactory;
use App\Security\TrustedDevice\TrustedDeviceCookieFactory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use SymfonyCasts\Bundle\ResetPassword\Exception\ResetPasswordExceptionInterface;
use SymfonyCasts\Bundle\ResetPassword\ResetPasswordHelperInterface;

/**
 * Sets a new password from an emailed reset link. The token travels in the JSON body, never in
 * the URL of an API call.
 *
 * Unlike a password change, no session is issued: whoever holds the link has proven control of the
 * mailbox, not knowledge of the account, so they go through a normal login (with 2FA) afterwards.
 */
#[Route('/api/auth')]
final class ResetPasswordController extends AbstractController
{
    public function __construct(
        private readonly ResetPasswordHelperInterface $resetPasswordHelper,
        private readonly ResetPasswordRequestRepository $resetPasswordRequestRepository,
        private readonly PasswordChanger $passwordChanger,
        private readonly RefreshTokenCookieFactory $refreshTokenCookieFactory,
        private readonly TrustedDeviceCookieFactory $trustedDeviceCookieFactory,
        private readonly RateLimitGuard $rateLimitGuard,
        #[Autowire(service: 'limiter.reset_password_ip')]
        private readonly RateLimiterFactory $resetPasswordIpLimiter,
        #[Autowire(service: 'limiter.reset_password_identifier')]
        private readonly RateLimiterFactory $resetPasswordIdentifierLimiter,
    ) {
    }

    #[Route('/reset-password', name: 'app_auth_reset_password', methods: ['POST'])]
    public function __invoke(#[MapRequestPayload] ResetPasswordRequest $payload, Request $request): JsonResponse
    {
        $this->rateLimitGuard->consume($this->resetPasswordIpLimiter, $request->getClientIp() ?? 'unknown');
        $this->rateLimitGuard->consume($this->resetPasswordIdentifierLimiter, substr($payload->token, 0, 20));

        try {
            $user = $this->resetPasswordHelper->validateTokenAndFetchUser($payload->token);
        } catch (ResetPasswordExceptionInterface) {
            $user = null;
        }

        // Consuming the request(s) is what makes the link single-use: of two concurrent submissions
        // of the same link, only the one that actually deletes a row gets past this point.
        if (!$user instanceof User || 0 === $this->resetPasswordRequestRepository->removeAllForUser($user)) {
            throw new HttpException(Response::HTTP_BAD_REQUEST, 'Invalid or expired reset link.');
        }

        // Following the link proves control of the mailbox — the same thing email verification does.
        if (!$user->isEmailVerified()) {
            $user->markEmailVerified();
        }

        $this->passwordChanger->change($user, $payload->newPassword, AuditEventType::PasswordResetCompleted);

        $response = $this->json(['message' => 'Your password has been reset. You can now sign in.']);
        $response->headers->setCookie($this->refreshTokenCookieFactory->clear());
        $response->headers->setCookie($this->trustedDeviceCookieFactory->clear());

        return $response;
    }
}
