<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TrustedDeviceRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A device the user chose to trust for 30 days, skipping the email 2FA step on that device.
 *
 * The cookie value itself is never stored: only its SHA-256 hash ({@see self::$tokenHash}), so a
 * leaked database copy cannot be replayed as a trusted-device cookie.
 */
#[ORM\Entity(repositoryClass: TrustedDeviceRepository::class)]
#[ORM\Table(name: 'trusted_device')]
#[ORM\UniqueConstraint(name: 'uniq_trusted_device_token_hash', fields: ['tokenHash'])]
class TrustedDevice
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'trustedDevices')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 64)]
    private string $tokenHash;

    #[ORM\Column(length: 255)]
    private string $label;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastUsedAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    #[ORM\Column(length: 45, nullable: true)]
    private ?string $ipAtCreation;

    public function __construct(User $user, string $tokenHash, string $label, \DateTimeImmutable $expiresAt, ?string $ipAtCreation)
    {
        $this->id = Uuid::v7();
        $this->user = $user;
        $this->tokenHash = $tokenHash;
        $this->label = $label;
        $this->createdAt = new \DateTimeImmutable();
        $this->expiresAt = $expiresAt;
        $this->ipAtCreation = $ipAtCreation;
        $user->getTrustedDevices()->add($this);
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getTokenHash(): string
    {
        return $this->tokenHash;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getLastUsedAt(): ?\DateTimeImmutable
    {
        return $this->lastUsedAt;
    }

    public function markUsedNow(): static
    {
        $this->lastUsedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getRevokedAt(): ?\DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function revoke(): static
    {
        $this->revokedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getIpAtCreation(): ?string
    {
        return $this->ipAtCreation;
    }

    public function isActive(): bool
    {
        return null === $this->revokedAt && $this->expiresAt >= new \DateTimeImmutable();
    }
}
