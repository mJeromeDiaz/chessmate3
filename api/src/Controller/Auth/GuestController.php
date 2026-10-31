<?php

declare(strict_types=1);

namespace App\Controller\Auth;

use App\Entity\User;
use App\Enum\AuditEventType;
use App\Repository\UserRepository;
use App\Security\Audit\AuditLogger;
use App\Security\RateLimit\RateLimitGuard;
use App\Security\Session\AuthenticatedSessionFactory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * « Essayer sans compte » (docs/GUEST.md): opens a guest account and its session, as a sign-in
 * does (access token, refresh-token cookie). No email, no password, no invitation: the guest has
 * ROLE_GUEST only, and the API opens it a whitelist. Capped per IP and for the whole site.
 *
 * CSRF: a cross-site form posting here would replace the visitor's own session cookie by a guest's
 * (signing a real account out). The "X-Guest-Request" header, which a form cannot send (and a
 * cross-origin fetch only past the CORS policy), stops it, as on /api/auth/refresh.
 */
#[Route('/api/auth')]
final class GuestController extends AbstractController
{
    /** The identifier of the site-wide limiter. */
    private const ALL = 'all';

    public function __construct(
        private readonly UserRepository $users,
        private readonly AuthenticatedSessionFactory $sessionFactory,
        private readonly AuditLogger $auditLogger,
        private readonly RateLimitGuard $rateLimitGuard,
        #[Autowire(service: 'limiter.guest_ip')]
        private readonly RateLimiterFactoryInterface $guestIpLimiter,
        #[Autowire(service: 'limiter.guest_global')]
        private readonly RateLimiterFactoryInterface $guestGlobalLimiter,
    ) {
    }

    #[Route('/guest', name: 'app_auth_guest', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        if ('1' !== $request->headers->get('X-Guest-Request')) {
            throw new HttpException(Response::HTTP_FORBIDDEN, 'Missing guest request header.');
        }
        $this->rateLimitGuard->consume($this->guestIpLimiter, $request->getClientIp() ?? 'unknown');
        $this->rateLimitGuard->consume($this->guestGlobalLimiter, self::ALL);

        $guest = User::guest();
        $this->users->save($guest);
        $session = $this->sessionFactory->issueFor($guest);
        $this->auditLogger->log(AuditEventType::GuestCreated, $guest);

        $response = $this->json(['accessToken' => $session->accessToken], Response::HTTP_CREATED);
        $response->headers->setCookie($session->refreshCookie);

        return $response;
    }
}
