<?php

declare(strict_types=1);

namespace App\Controller\Profile;

use App\Dto\Profile\AddPasswordRequest;
use App\Dto\Profile\InfoRequest;
use App\Dto\Profile\PreferencesRequest;
use App\Dto\Profile\ThemeRequest;
use App\Dto\Profile\TimezoneRequest;
use App\Entity\AuthIdentity;
use App\Entity\User;
use App\Enum\AuthProvider;
use App\Enum\Avatar;
use App\Enum\BoardTheme;
use App\Enum\Theme;
use App\Security\Password\PasswordAdder;
use App\Security\Profile\HandleChecker;
use App\Security\Profile\IdentityUnlinker;
use App\Security\RateLimit\RateLimitGuard;
use App\Security\Session\AuthenticatedSessionFactory;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
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
        private readonly HandleChecker $handleChecker,
        #[Autowire(service: 'limiter.password_change_ip')]
        private readonly RateLimiterFactory $passwordIpLimiter,
        #[Autowire(service: 'limiter.password_change_identifier')]
        private readonly RateLimiterFactory $passwordIdentifierLimiter,
        #[Autowire(service: 'limiter.profile_write')]
        private readonly RateLimiterFactory $profileWriteLimiter,
        #[Autowire(service: 'limiter.profile_handle_check')]
        private readonly RateLimiterFactory $handleCheckLimiter,
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

    /**
     * Sets what the profile shows: display name, handle and avatar (each replaced, empty clears).
     * 422 `invalid_handle`/`reserved_handle`, 409 `handle_taken` (also when another account took it
     * between the check and the write: the unique index decides).
     */
    #[Route('/info', name: 'app_profile_info', methods: ['PUT'])]
    public function setInfo(#[MapRequestPayload] InfoRequest $payload, #[CurrentUser] User $user): JsonResponse
    {
        $this->rateLimitGuard->consume($this->profileWriteLimiter, $user->getUserIdentifier());

        $displayName = trim($payload->displayName ?? '');
        $handle = $this->handleChecker->normalize($payload->handle ?? '');
        $refusal = '' === $handle ? null : $this->handleChecker->refusal($handle, $user);
        if (null !== $refusal) {
            return $this->handleRefusal($refusal);
        }

        $user->setDisplayName('' === $displayName ? null : $displayName)
            ->setHandle('' === $handle ? null : $handle)
            ->setAvatar(null === $payload->avatar ? null : Avatar::from($payload->avatar));

        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            return $this->handleRefusal(HandleChecker::TAKEN);
        }

        return $this->json($this->describe($user));
    }

    /** Board colours, move sounds and the public profile flag, all replaced at once. */
    #[Route('/preferences', name: 'app_profile_preferences', methods: ['PUT'])]
    public function setPreferences(#[MapRequestPayload] PreferencesRequest $payload, #[CurrentUser] User $user): JsonResponse
    {
        $this->rateLimitGuard->consume($this->profileWriteLimiter, $user->getUserIdentifier());

        $user->setBoardTheme(BoardTheme::from($payload->boardTheme))
            ->setMoveSound((bool) $payload->moveSound)
            ->setPublicProfile((bool) $payload->publicProfile);
        $this->entityManager->flush();

        return $this->json($this->describe($user));
    }

    /**
     * Whether the signed-in user can take a handle, asked while typing: `{handle, available,
     * reason}` with the handle normalized and reason `invalid`, `reserved`, `taken` or null.
     */
    #[Route('/handle-availability', name: 'app_profile_handle_availability', methods: ['GET'])]
    public function handleAvailability(#[CurrentUser] User $user, #[MapQueryParameter] string $handle = ''): JsonResponse
    {
        $this->rateLimitGuard->consume($this->handleCheckLimiter, $user->getUserIdentifier());

        $handle = $this->handleChecker->normalize($handle);
        $refusal = $this->handleChecker->refusal($handle, $user);

        return $this->json(['handle' => $handle, 'available' => null === $refusal, 'reason' => $refusal]);
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

    private function handleRefusal(string $refusal): JsonResponse
    {
        return HandleChecker::TAKEN === $refusal
            ? $this->json(['error' => 'handle_taken', 'message' => 'This username is already used.'], Response::HTTP_CONFLICT)
            : $this->json(['error' => $refusal.'_handle', 'message' => 'This username cannot be used.'], Response::HTTP_UNPROCESSABLE_ENTITY);
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
            // Opens the admin dashboard in the SPA (the API checks ROLE_ADMIN on its own).
            'isAdmin' => \in_array('ROLE_ADMIN', $user->getRoles(), true),
            'timezone' => $user->getTimezone(),
            'theme' => $user->getTheme()?->value,
            'displayName' => $user->getDisplayName(),
            'handle' => $user->getHandle(),
            'avatar' => $user->getAvatar()?->value,
            'boardTheme' => $user->getBoardTheme()->value,
            'moveSound' => $user->hasMoveSound(),
            'publicProfile' => $user->isPublicProfile(),
            // Account deletion scheduled (frozen account): when it will be purged.
            'deletionScheduledAt' => $user->getDeletionScheduledAt()?->format(\DATE_ATOM),
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
