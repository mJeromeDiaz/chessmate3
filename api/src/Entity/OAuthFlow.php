<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\AuthProvider;
use App\Enum\OAuthFlowPurpose;
use App\Repository\OAuthFlowRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One OAuth authorization in progress, between the redirect to the provider and its callback.
 *
 * Holds what a session would otherwise hold (this API is stateless): the `state` and the PKCE code
 * verifier. The row is bound to the browser that started the flow by a random cookie
 * ({@see self::$bindingHash}); the callback must present both that cookie and the matching
 * `state`, which is what defeats login CSRF — a victim sent to a callback URL carrying the
 * attacker's code has no cookie for that flow. Single use and 10-minute lifetime.
 *
 * Only hashes of the cookie and state are stored. The code verifier is stored as-is: it is worth
 * nothing without the authorization code, which is itself single-use and short-lived.
 */
#[ORM\Entity(repositoryClass: OAuthFlowRepository::class)]
#[ORM\Table(name: 'oauth_flow')]
#[ORM\UniqueConstraint(name: 'uniq_oauth_flow_binding', fields: ['bindingHash'])]
#[ORM\Index(name: 'idx_oauth_flow_expires_at', fields: ['expiresAt'])]
class OAuthFlow
{
    public const TTL_SECONDS = 600;

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $consumedAt = null;

    public function __construct(
        #[ORM\Column(length: 20, enumType: AuthProvider::class)]
        private AuthProvider $provider,
        #[ORM\Column(length: 10, enumType: OAuthFlowPurpose::class)]
        private OAuthFlowPurpose $purpose,
        #[ORM\Column(length: 64, options: ['charset' => 'ascii', 'collation' => 'ascii_bin'])]
        private string $bindingHash,
        #[ORM\Column(length: 64, options: ['charset' => 'ascii', 'collation' => 'ascii_bin'])]
        private string $stateHash,
        #[ORM\Column(length: 128, options: ['charset' => 'ascii', 'collation' => 'ascii_bin'])]
        private string $codeVerifier,
        /** The user linking an account; null for a login. */
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
        private ?User $user = null,
    ) {
        if ($purpose->needsUser() && null === $user) {
            throw new \InvalidArgumentException('A link or grant flow needs the user it acts for.');
        }

        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
        $this->expiresAt = $this->createdAt->modify(sprintf('+%d seconds', self::TTL_SECONDS));
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getProvider(): AuthProvider
    {
        return $this->provider;
    }

    public function getPurpose(): OAuthFlowPurpose
    {
        return $this->purpose;
    }

    public function getStateHash(): string
    {
        return $this->stateHash;
    }

    public function getCodeVerifier(): string
    {
        return $this->codeVerifier;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }
}
