<?php

declare(strict_types=1);

namespace App\Entity\Gamification;

use App\Entity\User;
use App\Enum\Gamification\XpKind;
use App\Repository\Gamification\XpEntryRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One XP gain (docs/GAMIFICATION.md), written by {@see \App\Gamification\Xp\XpLedger} in plain
 * SQL (read-only entity), never changed. Idempotent on its source: a redelivered event gains nothing more. The
 * totals (level, module levels, a run's XP) are sums over these rows.
 */
#[ORM\Entity(repositoryClass: XpEntryRepository::class, readOnly: true)]
#[ORM\Table(name: 'gamification_xp_entry')]
#[ORM\UniqueConstraint(name: 'uniq_gamification_xp_entry_source', columns: ['source_type', 'source_id'])]
#[ORM\Index(name: 'idx_gamification_xp_entry_user_date', columns: ['user_id', 'local_date'])]
#[ORM\Index(name: 'idx_gamification_xp_entry_run', columns: ['training_run_id'])]
class XpEntry
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 16, enumType: XpKind::class)]
    private XpKind $kind;

    /** The module the gain belongs to (Module values), null for one that belongs to none. */
    #[ORM\Column(length: 32, nullable: true)]
    private ?string $module;

    #[ORM\Column(type: Types::SMALLINT, options: ['unsigned' => true])]
    private int $xp;

    #[ORM\Column(length: 32, options: ['charset' => 'ascii', 'collation' => 'ascii_bin'])]
    private string $sourceType;

    #[ORM\Column(length: 80, options: ['charset' => 'ascii', 'collation' => 'ascii_bin'])]
    private string $sourceId;

    /** The timed run the gain was earned in, if any (the run review's XP). */
    #[ORM\Column(type: 'uuid', nullable: true)]
    private ?Uuid $trainingRunId;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $localDate;

    #[ORM\Column]
    private \DateTimeImmutable $occurredAt;

    /**
     * The ledger writes these rows in plain SQL (idempotent insert); this constructor mirrors it.
     */
    public function __construct(
        User $user,
        XpKind $kind,
        ?string $module,
        int $xp,
        string $sourceType,
        string $sourceId,
        ?Uuid $trainingRunId,
        \DateTimeImmutable $localDate,
        \DateTimeImmutable $occurredAt,
    ) {
        $this->id = Uuid::v7();
        $this->user = $user;
        $this->kind = $kind;
        $this->module = $module;
        $this->xp = $xp;
        $this->sourceType = $sourceType;
        $this->sourceId = $sourceId;
        $this->trainingRunId = $trainingRunId;
        $this->localDate = $localDate;
        $this->occurredAt = $occurredAt;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getKind(): XpKind
    {
        return $this->kind;
    }

    public function getModule(): ?string
    {
        return $this->module;
    }

    public function getXp(): int
    {
        return $this->xp;
    }

    public function getSourceType(): string
    {
        return $this->sourceType;
    }

    public function getSourceId(): string
    {
        return $this->sourceId;
    }

    public function getTrainingRunId(): ?Uuid
    {
        return $this->trainingRunId;
    }

    public function getLocalDate(): \DateTimeImmutable
    {
        return $this->localDate;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
