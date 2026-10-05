<?php

declare(strict_types=1);

namespace App\Controller\Auth;

use App\Entity\RefreshToken;
use App\Entity\User;
use App\Repository\RefreshTokenRepository;
use App\Security\RateLimit\RateLimitGuard;
use App\Security\RefreshToken\RefreshTokenCookieFactory;
use App\Security\RefreshToken\RefreshTokenService;
use App\Security\UserAgent\UserAgentSummarizer;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Uid\Uuid;

/**
 * The signed-in user's active sessions (one per refresh-token family), shown on the profile as
 * "Dernières connexions", and closing one of them from another device. Under /api/auth because
 * the refresh cookie (Path=/api/auth) is what tells which session is asking; an access token is
 * still required (access_control).
 */
#[Route('/api/auth/sessions')]
final class SessionController extends AbstractController
{
    public function __construct(
        private readonly RefreshTokenRepository $repository,
        private readonly RefreshTokenService $refreshTokenService,
        private readonly RefreshTokenCookieFactory $cookieFactory,
        private readonly UserAgentSummarizer $userAgentSummarizer,
        private readonly RateLimitGuard $rateLimitGuard,
        #[Autowire(service: 'limiter.profile_write')]
        private readonly RateLimiterFactory $writeLimiter,
    ) {
    }

    #[Route('', name: 'app_auth_sessions_list', methods: ['GET'])]
    public function list(#[CurrentUser] User $user, Request $request): JsonResponse
    {
        $current = $this->currentFamily($request);

        $sessions = array_map(
            fn (RefreshToken $token): array => [
                'id' => $token->getFamilyId()->toRfc4122(),
                'current' => null !== $current && $current->equals($token->getFamilyId()),
                ...$this->userAgentSummarizer->describe($token->getUserAgent()),
                // The last byte (IPv4) or 80 bits (IPv6) zeroed: enough to recognise a network.
                'ip' => null === $token->getIp() ? null : IpUtils::anonymize($token->getIp()),
                'signedInAt' => $token->getSignedInAt()?->format(\DATE_ATOM),
                'lastActiveAt' => $token->getIssuedAt()?->format(\DATE_ATOM),
            ],
            $this->repository->findActiveForUser($user->getUserIdentifier()),
        );
        // The asking session first.
        usort($sessions, static fn (array $a, array $b): int => $b['current'] <=> $a['current']);

        return $this->json(['sessions' => $sessions]);
    }

    /**
     * Closes another session (its refresh token stops working, its access tokens at once). The
     * asking session is refused (409 `current_session`): that is a logout.
     */
    #[Route('/{id}', name: 'app_auth_sessions_revoke', methods: ['DELETE'])]
    public function revoke(string $id, #[CurrentUser] User $user, Request $request): JsonResponse
    {
        $this->rateLimitGuard->consume($this->writeLimiter, $user->getUserIdentifier());

        if (!Uuid::isValid($id)) {
            throw $this->createNotFoundException();
        }
        $familyId = Uuid::fromString($id);

        if (true === $this->currentFamily($request)?->equals($familyId)) {
            return $this->json(['error' => 'current_session', 'message' => 'Use logout to close this session.'], Response::HTTP_CONFLICT);
        }
        if (!$this->refreshTokenService->revokeFamilyOf($user, $familyId)) {
            throw $this->createNotFoundException();
        }

        return $this->json(['message' => 'Session closed.']);
    }

    private function currentFamily(Request $request): ?Uuid
    {
        $presented = $this->cookieFactory->readFrom($request);

        return null === $presented ? null : $this->refreshTokenService->activeFamilyOf($presented);
    }
}
