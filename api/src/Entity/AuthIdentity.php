<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\AuthProvider;
use App\Repository\AuthIdentityRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A third-party account (Google, Lichess) linked to a {@see User}.
 *
 * Never created implicitly from a matching email: linking only happens while the user is already
 * authenticated (from their profile) or after an explicit email confirmation, to avoid account
 * takeover through a same-address OAuth signup.
 */
#[ORM\Entity(repositoryClass: AuthIdentityRepository::class)]
#[ORM\Table(name: 'auth_identity')]
#[ORM\UniqueConstraint(name: 'uniq_provider_identity', fields: ['provider', 'providerUserId'])]
class AuthIdentity
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'authIdentities')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 20, enumType: AuthProvider::class)]
    private AuthProvider $provider;

    /** Opaque identifier from the provider: compared byte for byte (case and accents). */
    #[ORM\Column(length: 255, options: ['collation' => 'utf8mb4_bin'])]
    private string $providerUserId;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $providerEmail = null;

    /**
     * Provider-specific, non-secret profile data (e.g. Lichess rating, Google's email_verified flag
     * at link time). Never a token: that lives in {@see self::$accessTokenEncrypted}.
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(type: 'json')]
    private array $metadata = [];

    /**
     * Sodium-encrypted OAuth access token (base64 of nonce + ciphertext), kept only for Lichess so
     * future game imports can use it. Never logged or serialized.
     */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $accessTokenEncrypted = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(User $user, AuthProvider $provider, string $providerUserId)
    {
        $this->id = Uuid::v7();
        $this->user = $user;
        $this->provider = $provider;
        $this->providerUserId = $providerUserId;
        $this->createdAt = new \DateTimeImmutable();
        $user->getAuthIdentities()->add($this);
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getProvider(): AuthProvider
    {
        return $this->provider;
    }

    public function getProviderUserId(): string
    {
        return $this->providerUserId;
    }

    public function getProviderEmail(): ?string
    {
        return $this->providerEmail;
    }

    public function setProviderEmail(?string $providerEmail): static
    {
        $this->providerEmail = $providerEmail;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function setMetadata(array $metadata): static
    {
        $this->metadata = $metadata;

        return $this;
    }

    public function getAccessTokenEncrypted(): ?string
    {
        return $this->accessTokenEncrypted;
    }

    public function setAccessTokenEncrypted(?string $accessTokenEncrypted): static
    {
        $this->accessTokenEncrypted = $accessTokenEncrypted;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
