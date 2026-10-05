<?php

declare(strict_types=1);

namespace App\Entity\Gamification;

use App\Entity\User;
use App\Enum\Gamification\QuestTemplate;
use App\Repository\Gamification\QuestRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * The quest of one local week (docs/GAMIFICATION.md): drawn on its first read, completed once, its
 * reward then gained as XP (kind `quest`, source this quest).
 */
#[ORM\Entity(repositoryClass: QuestRepository::class)]
#[ORM\Table(name: 'gamification_quest')]
#[ORM\UniqueConstraint(name: 'uniq_gamification_quest_user_week', columns: ['user_id', 'week_start'])]
class Quest
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private User $user,
        /** Monday of the local week. */
        #[ORM\Column(type: Types::DATE_IMMUTABLE)]
        private \DateTimeImmutable $weekStart,
        #[ORM\Column(length: 24, enumType: QuestTemplate::class)]
        private QuestTemplate $template,
        /** The theme of a weak-theme quest (Lichess key). */
        #[ORM\Column(length: 32, nullable: true, options: ['charset' => 'ascii', 'collation' => 'ascii_bin'])]
        private ?string $theme,
        #[ORM\Column(type: Types::SMALLINT, options: ['unsigned' => true])]
        private int $goal,
        #[ORM\Column(type: Types::SMALLINT, options: ['unsigned' => true])]
        private int $reward,
        \DateTimeImmutable $createdAt,
    ) {
        $this->id = Uuid::v7();
        $this->createdAt = $createdAt;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getWeekStart(): \DateTimeImmutable
    {
        return $this->weekStart;
    }

    public function getTemplate(): QuestTemplate
    {
        return $this->template;
    }

    public function getTheme(): ?string
    {
        return $this->theme;
    }

    public function getGoal(): int
    {
        return $this->goal;
    }

    public function getReward(): int
    {
        return $this->reward;
    }

    public function getCompletedAt(): ?\DateTimeImmutable
    {
        return $this->completedAt;
    }

    public function complete(\DateTimeImmutable $at): void
    {
        $this->completedAt ??= $at;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
