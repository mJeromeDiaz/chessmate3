<?php

declare(strict_types=1);

namespace App\Controller\EarlyAccess;

use App\Dto\EarlyAccess\AccessRequestInput;
use App\Repository\EarlyAccess\AccessRequestRepository;
use App\Security\RateLimit\RateLimitGuard;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

/**
 * "Demander l'accès": a visitor leaves an address on the waiting list (docs/EARLY_ACCESS.md).
 *
 * Public, so it says nothing about anyone: the same 202 whether the address is new, already on the
 * list or already has an account. Nothing is emailed (the form can't be used to send mail to a third
 * party). Rate limited per IP and overall; a filled `website` honeypot is answered 202 and dropped.
 */
final class AccessRequestController extends AbstractController
{
    public function __construct(
        private readonly AccessRequestRepository $requests,
        private readonly RateLimitGuard $rateLimitGuard,
        #[Autowire(service: 'limiter.access_request_ip')]
        private readonly RateLimiterFactory $ipLimiter,
        #[Autowire(service: 'limiter.access_request_global')]
        private readonly RateLimiterFactory $globalLimiter,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('/api/auth/invitation/request', name: 'app_early_access_request', methods: ['POST'])]
    public function __invoke(#[MapRequestPayload] AccessRequestInput $payload, Request $request): JsonResponse
    {
        $this->rateLimitGuard->consume($this->ipLimiter, $request->getClientIp() ?? 'unknown');

        if (null === $payload->website || '' === $payload->website) {
            $this->rateLimitGuard->consume($this->globalLimiter, 'all');
            $this->requests->add(mb_strtolower(trim($payload->email)), $this->clock->now());
        }

        return $this->json(['message' => 'Your request is recorded.'], Response::HTTP_ACCEPTED);
    }
}
