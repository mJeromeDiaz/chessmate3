<?php

declare(strict_types=1);

namespace App\Entity\EarlyAccess;

use App\Entity\User;
use App\Enum\EarlyAccess\InvitationAction;
use App\Repository\EarlyAccess\InvitationLogRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One line of the invitation audit trail (docs/EARLY_ACCESS.md): what happened to a key, who did
 * it. Never holds a key, only its hint.
 */
#[ORM\Entity(repositoryClass: InvitationLogRepository::class)]
#[ORM\Table(name: 'early_access_invitation_log')]
#[ORM\Index(name: 'idx_early_access_invitation_log_created', columns: ['created_at'])]
class InvitationLog
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: InvitationKey::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private InvitationKey $invitation;

    #[ORM\Column(length: 24, enumType: InvitationAction::class)]
    private InvitationAction $action;

    /** The admin who acted, or the account that used the key; null for the worker. */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $actor;

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json')]
    private array $details;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /**
     * @param array<string, mixed> $details never a key
     */
    public function __construct(InvitationKey $invitation, InvitationAction $action, ?User $actor, \DateTimeImmutable $now, array $details = [])
    {
        $this->id = Uuid::v7();
        $this->invitation = $invitation;
        $this->action = $action;
        $this->actor = $actor;
        $this->createdAt = $now;
        $this->details = $details;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getInvitation(): InvitationKey
    {
        return $this->invitation;
    }

    public function getAction(): InvitationAction
    {
        return $this->action;
    }

    public function getActor(): ?User
    {
        return $this->actor;
    }

    /**
     * @return array<string, mixed>
     */
    public function getDetails(): array
    {
        return $this->details;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
