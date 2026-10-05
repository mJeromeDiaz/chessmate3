<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\RefreshTokenRepository;
use Doctrine\ORM\Mapping as ORM;
use Gesdinet\JWTRefreshTokenBundle\Entity\RefreshToken as BaseRefreshToken;
use Symfony\Component\Uid\Uuid;

/**
 * A refresh token, extending gesdinet's storage model with the fields needed for reuse detection.
 *
 * The bundle's own rotation (its Security firewall authenticator, `AttachRefreshTokenOnSuccessListener`)
 * is intentionally not used: it hard-deletes the old token on rotation, which leaves no way to tell
 * "this token was never issued" apart from "this token was already used once and rotated out" when it
 * is presented a second time — exactly the distinction reuse detection needs. Instead,
 * {@see \App\Security\RefreshToken\RefreshTokenService} rotates tokens manually from a plain
 * controller: the old row is kept and logically revoked ({@see self::$revokedAt}) rather than
 * deleted, and every token descended from the same login shares a {@see self::$familyId} so that
 * replaying an already-revoked token can revoke the whole family at once.
 *
 * Each token's own `valid` is the idle timeout; {@see self::$familyExpiresAt} is the absolute one,
 * set at login and carried forward unchanged by every rotation, so no token of the family can
 * outlive it.
 */
#[ORM\Entity(repositoryClass: RefreshTokenRepository::class)]
// Stored as a prefixed SHA-256 hex digest (gesdinet's HashedRefreshTokenManager): binary ASCII.
#[ORM\AttributeOverrides([
    new ORM\AttributeOverride(name: 'refreshToken', column: new ORM\Column(name: 'refresh_token', length: 128, unique: true, options: ['charset' => 'ascii', 'collation' => 'ascii_bin'])),
])]
class RefreshToken extends BaseRefreshToken
{
    #[ORM\Column(type: 'uuid')]
    private Uuid $familyId;

    #[ORM\Column]
    private \DateTimeImmutable $familyExpiresAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    /**
     * When the family started (the sign-in), carried forward by every rotation like
     * {@see self::$familyExpiresAt}. Null for tokens issued before it was recorded.
     */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $signedInAt = null;

    /** When this very token was issued: the session's last activity while it is the active one. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $issuedAt = null;

    /**
     * User-Agent and IP of the request that issued this token (sign-in or refresh), shown in the
     * profile's active sessions. Personal data: gone with the token (purge of expired rows).
     */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $userAgent = null;

    #[ORM\Column(length: 45, nullable: true)]
    private ?string $ip = null;

    public function getFamilyId(): Uuid
    {
        return $this->familyId;
    }

    public function setFamilyId(Uuid $familyId): static
    {
        $this->familyId = $familyId;

        return $this;
    }

    public function getFamilyExpiresAt(): \DateTimeImmutable
    {
        return $this->familyExpiresAt;
    }

    public function setFamilyExpiresAt(\DateTimeImmutable $familyExpiresAt): static
    {
        $this->familyExpiresAt = $familyExpiresAt;

        return $this;
    }

    public function getRevokedAt(): ?\DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function isRevoked(): bool
    {
        return null !== $this->revokedAt;
    }

    public function getSignedInAt(): ?\DateTimeImmutable
    {
        return $this->signedInAt;
    }

    public function getIssuedAt(): ?\DateTimeImmutable
    {
        return $this->issuedAt;
    }

    public function getUserAgent(): ?string
    {
        return $this->userAgent;
    }

    public function getIp(): ?string
    {
        return $this->ip;
    }

    /** Records who asked for this token and when (see {@see self::$userAgent}). */
    public function setOrigin(?\DateTimeImmutable $signedInAt, \DateTimeImmutable $issuedAt, ?string $userAgent, ?string $ip): static
    {
        $this->signedInAt = $signedInAt;
        $this->issuedAt = $issuedAt;
        $this->userAgent = null === $userAgent ? null : mb_substr($userAgent, 0, 255);
        $this->ip = $ip;

        return $this;
    }
}
