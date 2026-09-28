<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\AuditEventType;
use App\Repository\AuditLogEntryRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A security-relevant event, for the audit log.
 *
 * {@see self::$metadata} must never contain a secret, code, token or password: only things safe to
 * show an administrator investigating an incident (e.g. a provider name, a device label).
 */
#[ORM\Entity(repositoryClass: AuditLogEntryRepository::class)]
#[ORM\Table(name: 'audit_log_entry')]
#[ORM\Index(name: 'idx_audit_user_created', columns: ['user_id', 'created_at'])]
class AuditLogEntry
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $user;

    #[ORM\Column(length: 64, enumType: AuditEventType::class)]
    private AuditEventType $eventType;

    #[ORM\Column(length: 45, nullable: true)]
    private ?string $ip;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $userAgent;

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json')]
    private array $metadata;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        AuditEventType $eventType,
        ?User $user,
        ?string $ip,
        ?string $userAgent,
        array $metadata = [],
    ) {
        $this->id = Uuid::v7();
        $this->eventType = $eventType;
        $this->user = $user;
        $this->ip = $ip;
        $this->userAgent = null === $userAgent ? null : mb_substr($userAgent, 0, 255);
        $this->metadata = $metadata;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function getEventType(): AuditEventType
    {
        return $this->eventType;
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
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
