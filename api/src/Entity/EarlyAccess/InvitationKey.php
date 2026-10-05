<?php

declare(strict_types=1);

namespace App\Entity\EarlyAccess;

use App\Entity\User;
use App\Enum\EarlyAccess\EmailStatus;
use App\Enum\EarlyAccess\InvitationStatus;
use App\Repository\EarlyAccess\InvitationKeyRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * An early access invitation (docs/EARLY_ACCESS.md): a single-use key, sent to an email address,
 * that opens one account (password or OAuth sign-up, any address). Only the key's sha256 is kept:
 * the admin sees the key once, and a resend replaces it with a new one on the same invitation.
 *
 * Never deleted: revoking it, or letting it expire, is what ends it. Its status is derived from its
 * dates ({@see self::getStatus()}), so it expires on time without a cron.
 */
#[ORM\Entity(repositoryClass: InvitationKeyRepository::class)]
#[ORM\Table(name: 'early_access_invitation_key')]
#[ORM\UniqueConstraint(name: 'uniq_early_access_invitation_key_hash', columns: ['key_hash'])]
#[ORM\Index(name: 'idx_early_access_invitation_key_created', columns: ['created_at'])]
class InvitationKey
{
    /** Key length, in characters of [A-Za-z0-9] (~190 bits). */
    public const KEY_LENGTH = 32;
    /** Characters of the key shown to the admin to tell keys apart. */
    public const HINT_LENGTH = 4;
    public const DEFAULT_LIFETIME = '+7 days';

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    /** sha256 of the current key, what a sign-up is matched with. */
    #[ORM\Column(length: 64, options: ['fixed' => true, 'charset' => 'ascii', 'collation' => 'ascii_bin'])]
    private string $keyHash;

    /** The first characters of the current key. */
    #[ORM\Column(length: 4, options: ['fixed' => true, 'charset' => 'ascii', 'collation' => 'ascii_bin'])]
    private string $keyHint;

    /** Where the invitation is sent. The key is not bound to it: any address can sign up with it. */
    #[ORM\Column(length: 180)]
    private string $email;

    /** The admin who created it (null once their account is purged). */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /** Null: never expires. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $expiresAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $usedAt = null;

    /** The account opened with it (null if that account was purged since: usedAt stays). */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $usedBy = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    /** Delivery of the email of the current key. */
    #[ORM\Column(length: 8, enumType: EmailStatus::class)]
    private EmailStatus $emailStatus = EmailStatus::Pending;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $emailSentAt = null;

    /** Emails queued: 1 at creation, +1 per resend. */
    #[ORM\Column(type: 'smallint', options: ['unsigned' => true])]
    private int $sendCount = 1;

    public function __construct(string $email, string $keyHash, string $keyHint, ?User $createdBy, \DateTimeImmutable $now, ?\DateTimeImmutable $expiresAt)
    {
        $this->id = Uuid::v7();
        $this->email = $email;
        $this->keyHash = $keyHash;
        $this->keyHint = $keyHint;
        $this->createdBy = $createdBy;
        $this->createdAt = $now;
        $this->expiresAt = $expiresAt;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getKeyHash(): string
    {
        return $this->keyHash;
    }

    public function getKeyHint(): string
    {
        return $this->keyHint;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getExpiresAt(): ?\DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getUsedAt(): ?\DateTimeImmutable
    {
        return $this->usedAt;
    }

    public function getUsedBy(): ?User
    {
        return $this->usedBy;
    }

    public function getRevokedAt(): ?\DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function getEmailStatus(): EmailStatus
    {
        return $this->emailStatus;
    }

    public function getEmailSentAt(): ?\DateTimeImmutable
    {
        return $this->emailSentAt;
    }

    public function getSendCount(): int
    {
        return $this->sendCount;
    }

    public function getStatus(\DateTimeImmutable $now): InvitationStatus
    {
        return match (true) {
            null !== $this->usedAt => InvitationStatus::Used,
            null !== $this->revokedAt => InvitationStatus::Revoked,
            null !== $this->expiresAt && $this->expiresAt <= $now => InvitationStatus::Expired,
            default => InvitationStatus::Pending,
        };
    }

    /**
     * A new key on the same invitation (the previous one stops working), its email queued again.
     */
    public function renewKey(string $keyHash, string $keyHint, ?\DateTimeImmutable $expiresAt): void
    {
        $this->keyHash = $keyHash;
        $this->keyHint = $keyHint;
        $this->expiresAt = $expiresAt;
        $this->emailStatus = EmailStatus::Pending;
        $this->emailSentAt = null;
        ++$this->sendCount;
    }

    /**
     * @param User|null $user the account opened with it; null when the sign-up opened none (its
     *                        email was taken: the key is spent all the same, see docs/EARLY_ACCESS.md)
     */
    public function markUsed(\DateTimeImmutable $now, ?User $user): void
    {
        $this->usedAt = $now;
        $this->usedBy = $user;
    }

    public function revoke(\DateTimeImmutable $now): void
    {
        $this->revokedAt ??= $now;
    }

    public function markSent(\DateTimeImmutable $now): void
    {
        $this->emailStatus = EmailStatus::Sent;
        $this->emailSentAt = $now;
    }

    public function markSendFailed(): void
    {
        $this->emailStatus = EmailStatus::Failed;
    }
}
