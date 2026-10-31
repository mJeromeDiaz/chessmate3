<?php

declare(strict_types=1);

namespace App\Controller\Auth;

use App\Dto\Auth\RegisterRequest;
use App\Entity\User;
use App\Enum\AuditEventType;
use App\Guest\GuestSession;
use App\Repository\UserRepository;
use App\Security\Audit\AuditLogger;
use App\Security\EmailVerification\EmailVerifier;
use App\Security\RateLimit\RateLimitGuard;
use App\Security\Registration\RegistrationGateInterface;
use App\Security\Registration\RegistrationRefusedException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Registration is deliberately indistinguishable whether the email is already taken: same 202,
 * same generic message, same amount of work done (the password is always hashed), so the endpoint
 * cannot be used to enumerate accounts.
 *
 * It takes an invitation key ({@see RegistrationGateInterface}). A refused key is answered 422
 * with its reason: that says nothing about accounts. A valid key is spent even when the email is
 * taken, else the key would still work afterwards and tell its holder the address has an account.
 *
 * Sent by a guest's browser (docs/GUEST.md, recognised by its refresh cookie), the account is that
 * guest: it gets the email and the password now, and becomes a real account once the email is
 * verified (until then it plays on as a guest).
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
        private readonly RegistrationGateInterface $registrationGate,
        private readonly EntityManagerInterface $entityManager,
        private readonly GuestSession $guestSession,
        #[Autowire(service: 'limiter.register_ip')]
        private readonly RateLimiterFactoryInterface $registerIpLimiter,
        #[Autowire(service: 'limiter.register_identifier')]
        private readonly RateLimiterFactoryInterface $registerIdentifierLimiter,
    ) {
    }

    #[Route('/register', name: 'app_auth_register', methods: ['POST'])]
    public function __invoke(#[MapRequestPayload] RegisterRequest $payload, Request $request): JsonResponse
    {
        $email = strtolower(trim($payload->email));

        $this->rateLimitGuard->consume($this->registerIpLimiter, $request->getClientIp() ?? 'unknown');
        $this->rateLimitGuard->consume($this->registerIdentifierLimiter, $email);

        try {
            $ticket = $this->registrationGate->admit($payload->invitationKey);

            $hashed = $this->passwordHasher->hashPassword(new User(), $payload->password);
            $candidate = $this->guestSession->of($request) ?? new User();

            // Not wrapInTransaction(): it would close the entity manager on a refusal. The gate
            // refuses before anything is persisted, so the rollback leaves nothing behind. The
            // candidate (a guest is a managed entity) changes only once the email is free.
            $created = $this->entityManager->getConnection()->transactional(function () use ($ticket, $candidate, $email, $hashed, $payload): bool {
                if (null !== $this->userRepository->findOneByEmail($email)) {
                    $this->registrationGate->redeem($ticket, null, 'password');
                    $this->entityManager->flush();

                    return false;
                }
                $this->registrationGate->redeem($ticket, $candidate, 'password');
                $candidate->setEmail($email);
                $candidate->setPassword($hashed);
                // A guest keeps the timezone it set when the browser sends none.
                $candidate->setTimezone($payload->timezone ?? $candidate->getTimezone());
                $this->userRepository->save($candidate);

                return true;
            });
        } catch (RegistrationRefusedException $refusal) {
            return $this->json(['error' => $refusal->reason, 'message' => $refusal->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($created) {
            $this->emailVerifier->sendVerificationEmail($candidate);
            $this->auditLogger->log(AuditEventType::RegistrationRequested, $candidate);
        }

        return $this->json(
            ['message' => 'If this email address can be used, a verification message has been sent.'],
            Response::HTTP_ACCEPTED,
        );
    }
}
