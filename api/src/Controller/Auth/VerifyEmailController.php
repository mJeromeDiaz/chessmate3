<?php

declare(strict_types=1);

namespace App\Controller\Auth;

use App\Dto\Auth\ResendVerificationEmailRequest;
use App\Enum\AuditEventType;
use App\Repository\UserRepository;
use App\Security\Audit\AuditLogger;
use App\Security\EmailVerification\EmailVerifier;
use App\Security\RateLimit\RateLimitGuard;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;
use SymfonyCasts\Bundle\VerifyEmail\Exception\VerifyEmailExceptionInterface;

#[Route('/api/auth')]
final class VerifyEmailController extends AbstractController
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly EmailVerifier $emailVerifier,
        private readonly AuditLogger $auditLogger,
        private readonly RateLimitGuard $rateLimitGuard,
        #[Autowire(service: 'limiter.verify_email_resend_ip')]
        private readonly RateLimiterFactory $resendIpLimiter,
        #[Autowire(service: 'limiter.verify_email_resend_identifier')]
        private readonly RateLimiterFactory $resendIdentifierLimiter,
        #[Autowire('%env(FRONTEND_URL)%')]
        private readonly string $frontendUrl,
    ) {
    }

    #[Route('/verify-email/{id}', name: 'app_verify_email', methods: ['GET'])]
    public function confirm(string $id, Request $request): RedirectResponse
    {
        $user = Uuid::isValid($id) ? $this->userRepository->findOneByUuid(Uuid::fromString($id)) : null;

        if (null === $user) {
            return new RedirectResponse($this->frontendUrl.'/#/login?verified=0');
        }

        $pendingEmail = $user->getPendingEmail();

        // The address was free when requested, but someone may have claimed it since.
        if (null !== $pendingEmail && null !== $this->userRepository->findOneByEmail($pendingEmail)) {
            return new RedirectResponse($this->frontendUrl.'/#/login?verified=0');
        }

        try {
            $this->emailVerifier->confirmEmail($request, $user);
            $this->userRepository->save($user);
        } catch (VerifyEmailExceptionInterface|UniqueConstraintViolationException) {
            return new RedirectResponse($this->frontendUrl.'/#/login?verified=0');
        }

        $this->auditLogger->log(AuditEventType::EmailVerified, $user);

        return new RedirectResponse($this->frontendUrl.'/#/login?verified=1');
    }

    #[Route('/verify-email/resend', name: 'app_verify_email_resend', methods: ['POST'])]
    public function resend(#[MapRequestPayload] ResendVerificationEmailRequest $payload, Request $request): JsonResponse
    {
        $email = strtolower(trim($payload->email));

        $this->rateLimitGuard->consume($this->resendIpLimiter, $request->getClientIp() ?? 'unknown');
        $this->rateLimitGuard->consume($this->resendIdentifierLimiter, $email);

        $user = $this->userRepository->findOneByEmail($email);

        if (null !== $user && !$user->isEmailVerified()) {
            $this->emailVerifier->sendVerificationEmail($user);
            $this->auditLogger->log(AuditEventType::EmailVerificationResent, $user);
        }

        return $this->json(
            ['message' => 'If this email address needs verifying, a new message has been sent.'],
            Response::HTTP_ACCEPTED,
        );
    }
}
