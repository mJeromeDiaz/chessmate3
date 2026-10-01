<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\MfaChallengeRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * The step-2 state of an email+password login: a code was sent, and this is what ties a submitted
 * code back to the specific login attempt that requested it ("lié au jeton mfa_pending").
 *
 * Both {@see self::$pendingTokenHash} and {@see self::$codeHash} store hashes, never the plaintext
 * values handed to the client/user. The pending token (256 random bits) is plain SHA-256 — see
 * {@see \App\Security\TwoFactor\MfaChallengeService}; the code hash, when the method uses one, is
 * owned by that method (see {@see \App\Security\TwoFactor\EmailCodeTwoFactorMethod}). It's nullable
 * because not every method has a server-generated code (a future TOTP method wouldn't).
 */
#[ORM\Entity(repositoryClass: MfaChallengeRepository::class)]
#[ORM\Table(name: 'mfa_challenge')]
#[ORM\UniqueConstraint(name: 'uniq_mfa_pending_token_hash', fields: ['pendingTokenHash'])]
class MfaChallenge
{
    public const MAX_ATTEMPTS = 5;

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 32)]
    private string $method;

    #[ORM\Column(length: 64, options: ['charset' => 'ascii', 'collation' => 'ascii_bin'])]
    private string $pendingTokenHash;

    #[ORM\Column(length: 64, nullable: true, options: ['charset' => 'ascii', 'collation' => 'ascii_bin'])]
    private ?string $codeHash = null;

    #[ORM\Column]
    private int $attempts = 0;

    #[ORM\Column]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $consumedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lockedAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $lastSentAt;

    #[ORM\Column(length: 45, nullable: true)]
    private ?string $ip;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $userAgent;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        User $user,
        string $method,
        string $pendingTokenHash,
        \DateTimeImmutable $expiresAt,
        ?string $ip,
        ?string $userAgent,
    ) {
        $this->id = Uuid::v7();
        $this->user = $user;
        $this->method = $method;
        $this->pendingTokenHash = $pendingTokenHash;
        $this->expiresAt = $expiresAt;
        $this->ip = $ip;
        $this->userAgent = null === $userAgent ? null : mb_substr($userAgent, 0, 255);
        $this->createdAt = new \DateTimeImmutable();
        $this->lastSentAt = $this->createdAt;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function getPendingTokenHash(): string
    {
        return $this->pendingTokenHash;
    }

    public function getCodeHash(): ?string
    {
        return $this->codeHash;
    }

    public function setCodeHash(?string $codeHash): static
    {
        $this->codeHash = $codeHash;

        return $this;
    }

    public function getAttempts(): int
    {
        return $this->attempts;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getConsumedAt(): ?\DateTimeImmutable
    {
        return $this->consumedAt;
    }

    public function getLockedAt(): ?\DateTimeImmutable
    {
        return $this->lockedAt;
    }

    public function getLastSentAt(): \DateTimeImmutable
    {
        return $this->lastSentAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getIp(): ?string
    {
        return $this->ip;
    }

    public function getUserAgent(): ?string
    {
        return $this->userAgent;
    }

    /**
     * Whether this challenge can still be verified against: not consumed, not locked out, not
     * expired.
     *
     * A read-only pre-check: the state transitions themselves (spending an attempt, consuming,
     * locking) go through conditional bulk UPDATEs in {@see MfaChallengeRepository} so they stay
     * correct under concurrent requests.
     */
    public function isActive(): bool
    {
        return null === $this->consumedAt
            && null === $this->lockedAt
            && $this->expiresAt >= new \DateTimeImmutable();
    }

    /**
     * Resets the challenge for a resent code: fresh expiry and attempt counter, same challenge (so
     * the same mfa_pending token remains valid). The previous code is invalidated by the method's
     * {@see \App\Security\TwoFactor\TwoFactorMethodInterface::begin()} overwriting the code hash.
     */
    public function restart(\DateTimeImmutable $newExpiresAt): void
    {
        $this->codeHash = null;
        $this->expiresAt = $newExpiresAt;
        $this->attempts = 0;
        $this->lastSentAt = new \DateTimeImmutable();
    }
}
