<?php

declare(strict_types=1);

namespace App\Controller\Auth;

use App\Security\RefreshToken\RefreshTokenCookieFactory;
use App\Security\RefreshToken\RefreshTokenService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Revokes only the current session's refresh-token family — other devices stay signed in. Always
 * succeeds, even with no session cookie or an already-invalid one, since a client asking to be
 * logged out is never wrong to be told it worked.
 */
#[Route('/api/auth')]
final class LogoutController extends AbstractController
{
    public function __construct(
        private readonly RefreshTokenService $refreshTokenService,
        private readonly RefreshTokenCookieFactory $cookieFactory,
    ) {
    }

    #[Route('/logout', name: 'app_auth_logout', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $presentedToken = $this->cookieFactory->readFrom($request);

        if (null !== $presentedToken) {
            $this->refreshTokenService->revokeSession($presentedToken);
        }

        $response = $this->json(['message' => 'Logged out.']);
        $response->headers->setCookie($this->cookieFactory->clear());

        return $response;
    }
}
