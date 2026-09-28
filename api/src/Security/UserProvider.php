<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use App\EventListener\JwtTokenVersionListener;
use App\Repository\UserRepository;
use Lexik\Bundle\JWTAuthenticationBundle\Exception\InvalidTokenException;
use Lexik\Bundle\JWTAuthenticationBundle\Security\User\PayloadAwareUserProviderInterface;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Loads a {@see User} by its UUID, the identifier stored in JWTs and refresh tokens.
 *
 * Never loads by email: the email can be null or can change, and is never what security tokens
 * carry as the identifier ({@see User::getUserIdentifier()}).
 *
 * @implements UserProviderInterface<User>
 */
final readonly class UserProvider implements UserProviderInterface, PayloadAwareUserProviderInterface, PasswordUpgraderInterface
{
    public function __construct(private UserRepository $userRepository)
    {
    }

    #[\Override]
    public function loadUserByIdentifier(string $identifier): User
    {
        if (!Uuid::isValid($identifier)) {
            throw new UserNotFoundException(sprintf('Invalid user identifier "%s".', $identifier));
        }

        $user = $this->userRepository->findOneByUuid(Uuid::fromString($identifier));

        if (null === $user) {
            throw new UserNotFoundException(sprintf('User "%s" not found.', $identifier));
        }

        return $user;
    }

    /**
     * Called by Lexik's JWT authenticator on every authenticated request. On top of loading the
     * user, rejects an access token whose version claim no longer matches the user's
     * ({@see User::getTokenVersion()}) — e.g. one issued before a password change. The rejection is
     * indistinguishable from a forged or malformed token.
     *
     * @param array<string, mixed> $payload
     */
    #[\Override]
    public function loadUserByIdentifierAndPayload(string $identifier, array $payload): User
    {
        $user = $this->loadUserByIdentifier($identifier);

        if (($payload[JwtTokenVersionListener::CLAIM] ?? null) !== $user->getTokenVersion()) {
            throw new InvalidTokenException('Invalid JWT Token');
        }

        return $user;
    }

    #[\Override]
    public function refreshUser(UserInterface $user): UserInterface
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(sprintf('Instances of "%s" are not supported.', $user::class));
        }

        return $this->loadUserByIdentifier($user->getUserIdentifier());
    }

    #[\Override]
    public function supportsClass(string $class): bool
    {
        return User::class === $class || is_subclass_of($class, User::class);
    }

    #[\Override]
    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        $this->userRepository->upgradePassword($user, $newHashedPassword);
    }
}
