<?php

declare(strict_types=1);

namespace App\Controller\Auth;

use App\Dto\Auth\ForgotPasswordRequest;
use App\Security\Password\Message\PasswordResetRequested;
use App\Security\RateLimit\RateLimitGuard;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Anti-enumeration by construction: this endpoint never looks the email up. It only queues a
 * message ({@see \App\Security\Password\Message\PasswordResetRequestedHandler} does the rest in a
 * worker), so the response — status, body, and the work done to produce it — is the same whether
 * or not an account exists.
 */
#[Route('/api/auth')]
final class ForgotPasswordController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly RateLimitGuard $rateLimitGuard,
        #[Autowire(service: 'limiter.forgot_password_ip')]
        private readonly RateLimiterFactory $forgotPasswordIpLimiter,
        #[Autowire(service: 'limiter.forgot_password_identifier')]
        private readonly RateLimiterFactory $forgotPasswordIdentifierLimiter,
    ) {
    }

    #[Route('/forgot-password', name: 'app_auth_forgot_password', methods: ['POST'])]
    public function __invoke(#[MapRequestPayload] ForgotPasswordRequest $payload, Request $request): JsonResponse
    {
        $email = strtolower(trim($payload->email));

        $this->rateLimitGuard->consume($this->forgotPasswordIpLimiter, $request->getClientIp() ?? 'unknown');
        $this->rateLimitGuard->consume($this->forgotPasswordIdentifierLimiter, $email);

        $this->messageBus->dispatch(new PasswordResetRequested($email, $request->getClientIp(), $request->headers->get('User-Agent')));

        return $this->json(
            ['message' => 'If an account exists for this email address, a message has been sent.'],
            Response::HTTP_ACCEPTED,
        );
    }
}
