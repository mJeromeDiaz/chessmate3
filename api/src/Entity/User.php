<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\Avatar;
use App\Enum\BoardTheme;
use App\Enum\Theme;
use App\Repository\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Uuid;

/**
 * A ChessMate account.
 *
 * The Symfony security identifier ({@see self::getUserIdentifier()}) is the UUID, never the email:
 * email is nullable (a Lichess-only signup may have none) and can change, but the identifier used in
 * the JWT `username` claim and in the refresh token's `username` column must never change.
 */
#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'app_user')]
#[ORM\UniqueConstraint(name: 'uniq_user_email', fields: ['email'])]
#[ORM\UniqueConstraint(name: 'uniq_user_handle', fields: ['handle'])]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $email = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $emailVerifiedAt = null;

    /**
     * An address the user asked to use (when adding a password to an account that has none) and
     * hasn't confirmed yet. It only becomes {@see self::$email} once verified, so an unconfirmed
     * claim never reserves an address someone else may own.
     */
    #[ORM\Column(length: 180, nullable: true)]
    private ?string $pendingEmail = null;

    #[ORM\Column(nullable: true)]
    private ?string $password = null;

    /** @var list<string> */
    #[ORM\Column]
    private array $roles = [];

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /**
     * Copied into every access token as the "ver" claim and checked on each request
     * ({@see \App\Security\UserProvider::loadUserByIdentifierAndPayload()}): bumping it instantly
     * invalidates every access token already issued, instead of letting them live out their 15 minutes.
     */
    #[ORM\Column(options: ['default' => 0])]
    private int $tokenVersion = 0;

    /**
     * IANA timezone (e.g. "Europe/Paris"), used only to compute local dates (activity days,
     * Woodpecker deadlines); every timestamp stays UTC. Null until the SPA reports it.
     */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $timezone = null;

    /** The colour theme chosen in the SPA; null until the user picks one. */
    #[ORM\Column(length: 8, nullable: true, enumType: Theme::class)]
    private ?Theme $theme = null;

    /** The name shown on the profile; null until chosen (the SPA then shows the email's local part). */
    #[ORM\Column(length: 40, nullable: true)]
    private ?string $displayName = null;

    /**
     * The public username ("@lea_echecs"): 3 to 20 of [a-z0-9_], unique, null until chosen
     * ({@see \App\Security\Profile\HandleChecker}). Lower case only, stored binary.
     */
    #[ORM\Column(length: 20, nullable: true, options: ['charset' => 'ascii', 'collation' => 'ascii_bin'])]
    private ?string $handle = null;

    /** The chess piece shown as avatar; null until chosen (the SPA then shows an initial). */
    #[ORM\Column(length: 8, nullable: true, enumType: Avatar::class)]
    private ?Avatar $avatar = null;

    /** The colours of the chessboard squares, on every board of the SPA. */
    #[ORM\Column(length: 12, enumType: BoardTheme::class, options: ['default' => 'wood'])]
    private BoardTheme $boardTheme = BoardTheme::Wood;

    /** Sounds of the moves played on the boards (move, capture, check). */
    #[ORM\Column(options: ['default' => true])]
    private bool $moveSound = true;

    /**
     * Whether other players may see the level and stats. Stored only: no page shows a profile to
     * other users yet. Private by default.
     */
    #[ORM\Column(options: ['default' => false])]
    private bool $publicProfile = false;

    /** @var Collection<int, AuthIdentity> */
    #[ORM\OneToMany(targetEntity: AuthIdentity::class, mappedBy: 'user', cascade: ['persist'], orphanRemoval: true)]
    private Collection $authIdentities;

    /** @var Collection<int, TrustedDevice> */
    #[ORM\OneToMany(targetEntity: TrustedDevice::class, mappedBy: 'user', cascade: ['persist'], orphanRemoval: true)]
    private Collection $trustedDevices;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
        $this->authIdentities = new ArrayCollection();
        $this->trustedDevices = new ArrayCollection();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): static
    {
        $this->email = $email;

        return $this;
    }

    public function getPendingEmail(): ?string
    {
        return $this->pendingEmail;
    }

    public function setPendingEmail(?string $pendingEmail): static
    {
        $this->pendingEmail = $pendingEmail;

        return $this;
    }

    /**
     * Makes the confirmed pending address the account's email.
     */
    public function confirmPendingEmail(\DateTimeImmutable $at = new \DateTimeImmutable()): static
    {
        if (null === $this->pendingEmail) {
            throw new \LogicException('No pending email to confirm.');
        }

        $this->email = $this->pendingEmail;
        $this->pendingEmail = null;
        $this->emailVerifiedAt = $at;

        return $this;
    }

    public function isEmailVerified(): bool
    {
        return null !== $this->emailVerifiedAt;
    }

    public function getEmailVerifiedAt(): ?\DateTimeImmutable
    {
        return $this->emailVerifiedAt;
    }

    public function markEmailVerified(\DateTimeImmutable $at = new \DateTimeImmutable()): static
    {
        $this->emailVerifiedAt = $at;

        return $this;
    }

    #[\Override]
    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function setPassword(?string $password): static
    {
        $this->password = $password;

        return $this;
    }

    public function hasPassword(): bool
    {
        return null !== $this->password;
    }

    /**
     * @return list<string>
     */
    #[\Override]
    public function getRoles(): array
    {
        $roles = $this->roles;
        $roles[] = 'ROLE_USER';

        return array_values(array_unique($roles));
    }

    /**
     * @param list<string> $roles
     */
    public function setRoles(array $roles): static
    {
        $this->roles = $roles;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getDisplayName(): ?string
    {
        return $this->displayName;
    }

    public function setDisplayName(?string $displayName): static
    {
        $this->displayName = $displayName;

        return $this;
    }

    public function getHandle(): ?string
    {
        return $this->handle;
    }

    public function setHandle(?string $handle): static
    {
        $this->handle = $handle;

        return $this;
    }

    public function getAvatar(): ?Avatar
    {
        return $this->avatar;
    }

    public function setAvatar(?Avatar $avatar): static
    {
        $this->avatar = $avatar;

        return $this;
    }

    public function getBoardTheme(): BoardTheme
    {
        return $this->boardTheme;
    }

    public function setBoardTheme(BoardTheme $boardTheme): static
    {
        $this->boardTheme = $boardTheme;

        return $this;
    }

    public function hasMoveSound(): bool
    {
        return $this->moveSound;
    }

    public function setMoveSound(bool $moveSound): static
    {
        $this->moveSound = $moveSound;

        return $this;
    }

    public function isPublicProfile(): bool
    {
        return $this->publicProfile;
    }

    public function setPublicProfile(bool $publicProfile): static
    {
        $this->publicProfile = $publicProfile;

        return $this;
    }

    public function getTheme(): ?Theme
    {
        return $this->theme;
    }

    public function setTheme(?Theme $theme): static
    {
        $this->theme = $theme;

        return $this;
    }

    public function getTimezone(): ?string
    {
        return $this->timezone;
    }

    /**
     * The timezone to compute local dates with: UTC while none is known.
     */
    public function getDateTimeZone(): \DateTimeZone
    {
        return new \DateTimeZone($this->timezone ?? 'UTC');
    }

    /**
     * @throws \InvalidArgumentException if not an IANA identifier
     */
    public function setTimezone(?string $timezone): static
    {
        if (null !== $timezone && !\in_array($timezone, \DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC), true)) {
            throw new \InvalidArgumentException(sprintf('Unknown timezone "%s".', $timezone));
        }
        $this->timezone = $timezone;

        return $this;
    }

    public function getTokenVersion(): int
    {
        return $this->tokenVersion;
    }

    public function bumpTokenVersion(): static
    {
        ++$this->tokenVersion;

        return $this;
    }

    /**
     * @return Collection<int, AuthIdentity>
     */
    public function getAuthIdentities(): Collection
    {
        return $this->authIdentities;
    }

    /**
     * @return Collection<int, TrustedDevice>
     */
    public function getTrustedDevices(): Collection
    {
        return $this->trustedDevices;
    }

    /**
     * Whether email + password sign-in works: it needs a password and a verified email (the login
     * identifier, and where the 2FA code goes).
     */
    public function canSignInWithPassword(): bool
    {
        return $this->hasPassword() && null !== $this->email && $this->isEmailVerified();
    }

    /**
     * How many independent ways this account can be signed into: a usable password counts as one
     * ({@see self::canSignInWithPassword()}), each linked OAuth identity counts as one more. The
     * last one may never be removed.
     */
    public function countAuthMethods(): int
    {
        return ($this->canSignInWithPassword() ? 1 : 0) + $this->authIdentities->count();
    }

    #[\Override]
    public function getUserIdentifier(): string
    {
        return $this->id->toRfc4122();
    }

    #[\Override]
    public function eraseCredentials(): void
    {
    }
}
