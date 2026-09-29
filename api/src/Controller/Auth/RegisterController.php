<?php

declare(strict_types=1);

namespace App\Controller\Auth;

use App\Dto\Auth\RegisterRequest;
use App\Entity\User;
use App\Enum\AuditEventType;
use App\Repository\UserRepository;
use App\Security\Audit\AuditLogger;
use App\Security\EmailVerification\EmailVerifier;
use App\Security\RateLimit\RateLimitGuard;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Registration is deliberately indistinguishable whether the email is already taken: same 202,
 * same generic message, same amount of work done (the password is always hashed), so the endpoint
 * cannot be used to enumerate accounts.
 */
#[Route('/api/auth')]
final class RegisterController extends AbstractController
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly EmailVerifier $emailVerifier,
        private readonly AuditLogger $auditLogger,
        private readonly RateLimitGuard $rateLimitGuard,
        #[Autowire(service: 'limiter.register_ip')]
        private readonly RateLimiterFactory $registerIpLimiter,
        #[Autowire(service: 'limiter.register_identifier')]
        private readonly RateLimiterFactory $registerIdentifierLimiter,
    ) {
    }

    #[Route('/register', name: 'app_auth_register', methods: ['POST'])]
    public function __invoke(#[MapRequestPayload] RegisterRequest $payload, Request $request): JsonResponse
    {
        $email = strtolower(trim($payload->email));

        $this->rateLimitGuard->consume($this->registerIpLimiter, $request->getClientIp() ?? 'unknown');
        $this->rateLimitGuard->consume($this->registerIdentifierLimiter, $email);

        $candidate = new User();
        $candidate->setEmail($email);
        $candidate->setPassword($this->passwordHasher->hashPassword($candidate, $payload->password));
        $candidate->setTimezone($payload->timezone);

        if (null === $this->userRepository->findOneByEmail($email)) {
            $this->userRepository->save($candidate);
            $this->emailVerifier->sendVerificationEmail($candidate);
            $this->auditLogger->log(AuditEventType::RegistrationRequested, $candidate);
        }

        return $this->json(
            ['message' => 'If this email address can be used, a verification message has been sent.'],
            Response::HTTP_ACCEPTED,
        );
    }
}
