<?php

declare(strict_types=1);

namespace App\Entity\EarlyAccess;

use App\Repository\EarlyAccess\AccessRequestRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A request to join the early access, left by a visitor on the sign-up screen (docs/EARLY_ACCESS.md).
 * Nothing is sent to the address: an admin reads the waiting list and invites whom they choose.
 * One row per address (inserted with INSERT IGNORE by {@see AccessRequestRepository::add()}).
 * Deleted on request (personal data); the invitation it led to stays.
 */
#[ORM\Entity(repositoryClass: AccessRequestRepository::class)]
#[ORM\Table(name: 'early_access_request')]
#[ORM\UniqueConstraint(name: 'uniq_early_access_request_email', columns: ['email'])]
class AccessRequest
{
    /** UUID v7: ordering by id is the order of arrival. */
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    /** Lowercased and trimmed. */
    #[ORM\Column(length: 180)]
    private string $email;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $invitedAt = null;

    /** The invitation created from it (invitations are never deleted). */
    #[ORM\ManyToOne(targetEntity: InvitationKey::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?InvitationKey $invitation = null;

    public function __construct(string $email, \DateTimeImmutable $now)
    {
        $this->id = Uuid::v7();
        $this->email = $email;
        $this->createdAt = $now;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getInvitedAt(): ?\DateTimeImmutable
    {
        return $this->invitedAt;
    }

    public function getInvitation(): ?InvitationKey
    {
        return $this->invitation;
    }

    public function markInvited(InvitationKey $invitation, \DateTimeImmutable $now): void
    {
        $this->invitation = $invitation;
        $this->invitedAt = $now;
    }
}
