<?php

declare(strict_types=1);

namespace App\Controller\Profile;

use App\Dto\Profile\AddPasswordRequest;
use App\Dto\Profile\ThemeRequest;
use App\Dto\Profile\TimezoneRequest;
use App\Entity\AuthIdentity;
use App\Entity\User;
use App\Enum\AuthProvider;
use App\Enum\Theme;
use App\Security\Password\PasswordAdder;
use App\Security\Profile\IdentityUnlinker;
use App\Security\RateLimit\RateLimitGuard;
use App\Security\Session\AuthenticatedSessionFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Uid\Uuid;

/**
 * The signed-in user's own account: what it is made of, adding a password, removing a linked
 * identity. Output is built field by field here — never a serialized entity — so no hash, token or
 * internal counter can leak.
 */
#[Route('/api/profile')]
final class ProfileController extends AbstractController
{
    public function __construct(
        private readonly PasswordAdder $passwordAdder,
        private readonly IdentityUnlinker $identityUnlinker,
        private readonly AuthenticatedSessionFactory $sessionFactory,
        private readonly RateLimitGuard $rateLimitGuard,
        private readonly EntityManagerInterface $entityManager,
        #[Autowire(service: 'limiter.password_change_ip')]
        private readonly RateLimiterFactory $passwordIpLimiter,
        #[Autowire(service: 'limiter.password_change_identifier')]
        private readonly RateLimiterFactory $passwordIdentifierLimiter,
    ) {
    }

    #[Route('', name: 'app_profile_show', methods: ['GET'])]
    public function show(#[CurrentUser] User $user): JsonResponse
    {
        return $this->json($this->describe($user));
    }

    #[Route('/password', name: 'app_profile_password_add', methods: ['POST'])]
    public function addPassword(#[MapRequestPayload] AddPasswordRequest $payload, #[CurrentUser] User $user, Request $request): JsonResponse
    {
        $this->rateLimitGuard->consume($this->passwordIpLimiter, $request->getClientIp() ?? 'unknown');
        $this->rateLimitGuard->consume($this->passwordIdentifierLimiter, $user->getUserIdentifier());

        try {
            $result = $this->passwordAdder->add($user, $payload->password, $payload->email);
        } catch (\DomainException) {
            throw new HttpException(Response::HTTP_CONFLICT, 'This account already has a password.');
        } catch (\InvalidArgumentException) {
            throw new HttpException(Response::HTTP_UNPROCESSABLE_ENTITY, 'An email address is required.');
        }

        if (PasswordAdder::RESULT_ADDED === $result) {
            return $this->json(['status' => $result, 'profile' => $this->describe($user)]);
        }

        // Same answer whether the address was free or already taken.
        return $this->json([
            'status' => $result,
            'message' => 'If this address can be used, a verification link has been sent to it. Your password will work once it is confirmed.',
        ], Response::HTTP_ACCEPTED);
    }

    /**
     * Sets the IANA timezone used for local dates (sent by the SPA after sign-in when the profile
     * has none, or chosen on the profile page). Past activity keeps its local dates.
     */
    #[Route('/timezone', name: 'app_profile_timezone', methods: ['PUT'])]
    public function setTimezone(#[MapRequestPayload] TimezoneRequest $payload, #[CurrentUser] User $user): JsonResponse
    {
        $user->setTimezone($payload->timezone);
        $this->entityManager->flush();

        return $this->json($this->describe($user));
    }

    /**
     * Sets the colour theme, so the SPA finds it again on another device (the browser keeps its
     * own copy to apply it before the profile is loaded).
     */
    #[Route('/theme', name: 'app_profile_theme', methods: ['PUT'])]
    public function setTheme(#[MapRequestPayload] ThemeRequest $payload, #[CurrentUser] User $user): JsonResponse
    {
        $user->setTheme(Theme::from($payload->theme));
        $this->entityManager->flush();

        return $this->json($this->describe($user));
    }

    #[Route('/identities/{id}', name: 'app_profile_identity_unlink', methods: ['DELETE'])]
    public function unlink(string $id, #[CurrentUser] User $user): JsonResponse
    {
        if (!Uuid::isValid($id)) {
            throw $this->createNotFoundException();
        }

        try {
            $user = $this->identityUnlinker->unlink($user, Uuid::fromString($id));
        } catch (\OutOfBoundsException) {
            throw $this->createNotFoundException();
        } catch (\DomainException) {
            return $this->json(['error' => 'last_auth_method', 'message' => 'You cannot remove the last way to sign into your account.'], Response::HTTP_CONFLICT);
        }

        // Every session was ended; the caller continues in a new one.
        $session = $this->sessionFactory->issueFor($user);
        $response = $this->json(['accessToken' => $session->accessToken, 'profile' => $this->describe($user)]);
        $response->headers->setCookie($session->refreshCookie);

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    private function describe(User $user): array
    {
        $canRemoveOne = $user->countAuthMethods() > 1;

        return [
            'id' => $user->getId()->toRfc4122(),
            'email' => $user->getEmail(),
            'emailVerified' => $user->isEmailVerified(),
            'pendingEmail' => $user->getPendingEmail(),
            'hasPassword' => $user->canSignInWithPassword(),
            'createdAt' => $user->getCreatedAt()->format(\DATE_ATOM),
            'timezone' => $user->getTimezone(),
            'theme' => $user->getTheme()?->value,
            'linkableProviders' => array_values(array_map(
                static fn (AuthProvider $provider): string => $provider->value,
                array_filter(AuthProvider::cases(), static fn (AuthProvider $provider): bool => !$user->getAuthIdentities()->exists(
                    static fn (int $key, AuthIdentity $identity): bool => $identity->getProvider() === $provider,
                )),
            )),
            'identities' => array_values(array_map(
                static function (AuthIdentity $identity) use ($canRemoveOne): array {
                    $metadata = $identity->getMetadata();

                    return [
                        'id' => $identity->getId()->toRfc4122(),
                        'provider' => $identity->getProvider()->value,
                        'providerEmail' => $identity->getProviderEmail(),
                        'username' => \is_string($metadata['username'] ?? null) ? $metadata['username'] : null,
                        'name' => \is_string($metadata['name'] ?? null) ? $metadata['name'] : null,
                        'ratings' => \is_array($metadata['ratings'] ?? null) ? $metadata['ratings'] : null,
                        // Extra scopes granted (Lichess study:read for the repertoire import).
                        'scopes' => \is_array($metadata['scopes'] ?? null) ? array_values(array_filter($metadata['scopes'], 'is_string')) : [],
                        'linkedAt' => $identity->getCreatedAt()->format(\DATE_ATOM),
                        'removable' => $canRemoveOne,
                    ];
                },
                $user->getAuthIdentities()->toArray(),
            )),
        ];
    }
}
