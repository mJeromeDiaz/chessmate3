<?php

declare(strict_types=1);

namespace App\Controller\Auth;

use App\Dto\Profile\DeletionConfirmRequest;
use App\Entity\User;
use App\Security\Account\AccountDeletion;
use App\Security\Account\AccountDeletionException;
use App\Security\RateLimit\RateLimitGuard;
use App\Security\RefreshToken\RefreshTokenCookieFactory;
use App\Security\RefreshToken\RefreshTokenService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Account deletion (docs/AUTH.md): ask for the email code, confirm it (the account is then frozen
 * for 30 days and signed out everywhere), cancel. An account without a verified email confirms
 * with a sign-in less than 10 minutes old. Under /api/auth so that the refresh cookie (path
 * /api/auth) tells when the asking session signed in. Errors: `{error, message}` with the reasons
 * of {@see AccountDeletionException}.
 */
#[Route('/api/auth/account-deletion')]
final class AccountDeletionController extends AbstractController
{
    public function __construct(
        private readonly AccountDeletion $deletion,
        private readonly RateLimitGuard $rateLimitGuard,
        private readonly RefreshTokenCookieFactory $cookieFactory,
        private readonly RefreshTokenService $refreshTokens,
        #[Autowire(service: 'limiter.account_deletion_code')]
        private readonly RateLimiterFactory $codeLimiter,
        #[Autowire(service: 'limiter.account_deletion_confirm')]
        private readonly RateLimiterFactory $confirmLimiter,
    ) {
    }

    /**
     * Starts a deletion: with a verified email, sends the code (202 `{method: "email", expiresAt}`);
     * without, tells whether the session signed in recently enough (200 `{method:
     * "recent_sign_in", recentSignIn}`).
     */
    #[Route('', name: 'app_auth_account_deletion_request', methods: ['POST'])]
    public function request(#[CurrentUser] User $user, Request $request): JsonResponse
    {
        if (AccountDeletion::METHOD_RECENT_SIGN_IN === $this->deletion->methodFor($user)) {
            if ($user->isFrozen()) {
                return $this->refusal(new AccountDeletionException(AccountDeletionException::FROZEN));
            }

            return $this->json([
                'method' => AccountDeletion::METHOD_RECENT_SIGN_IN,
                'recentSignIn' => $this->deletion->isRecentSignIn($this->signedInAt($user, $request)),
            ]);
        }
        $this->rateLimitGuard->consume($this->codeLimiter, $user->getUserIdentifier());
        try {
            $expiresAt = $this->deletion->sendCode($user);
        } catch (AccountDeletionException $e) {
            return $this->refusal($e);
        }

        return $this->json(['method' => AccountDeletion::METHOD_EMAIL, 'expiresAt' => $expiresAt->format(\DATE_ATOM)], Response::HTTP_ACCEPTED);
    }

    /**
     * Checks the code (or the recent sign-in): `{deletionScheduledAt}`; every session is closed,
     * the refresh cookie cleared.
     */
    #[Route('/confirm', name: 'app_auth_account_deletion_confirm', methods: ['POST'])]
    public function confirm(#[MapRequestPayload] DeletionConfirmRequest $payload, #[CurrentUser] User $user, Request $request): JsonResponse
    {
        $this->rateLimitGuard->consume($this->confirmLimiter, $user->getUserIdentifier());
        try {
            $scheduledAt = $this->deletion->confirm($user, $payload->code, $this->signedInAt($user, $request));
        } catch (AccountDeletionException $e) {
            return $this->refusal($e);
        }

        $response = $this->json(['deletionScheduledAt' => $scheduledAt->format(\DATE_ATOM)]);
        $response->headers->setCookie($this->cookieFactory->clear());

        return $response;
    }

    /** Cancels a scheduled deletion (idempotent): 204. */
    #[Route('/cancel', name: 'app_auth_account_deletion_cancel', methods: ['POST'])]
    public function cancel(#[CurrentUser] User $user): Response
    {
        $this->deletion->cancel($user);

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    private function signedInAt(User $user, Request $request): ?\DateTimeImmutable
    {
        $token = $this->cookieFactory->readFrom($request);

        return null === $token ? null : $this->refreshTokens->signedInAtOf($user, $token);
    }

    private function refusal(AccountDeletionException $e): JsonResponse
    {
        [$status, $message] = match ($e->reason) {
            AccountDeletionException::FROZEN => [Response::HTTP_CONFLICT, 'A deletion is already scheduled.'],
            AccountDeletionException::NO_EMAIL => [Response::HTTP_CONFLICT, 'This account has no verified email to send the code to.'],
            AccountDeletionException::TOO_SOON => [Response::HTTP_TOO_MANY_REQUESTS, 'Wait a few seconds before asking for a new code.'],
            AccountDeletionException::CODE_EXPIRED => [Response::HTTP_GONE, 'This code is no longer valid: ask for a new one.'],
            AccountDeletionException::SIGN_IN_REQUIRED => [Response::HTTP_FORBIDDEN, 'Sign in again to confirm the deletion.'],
            default => [Response::HTTP_UNPROCESSABLE_ENTITY, 'Wrong code.'],
        };

        return $this->json(['error' => $e->reason, 'message' => $message], $status);
    }
}
