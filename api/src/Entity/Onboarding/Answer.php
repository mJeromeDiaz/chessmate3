<?php

declare(strict_types=1);

namespace App\Entity\Onboarding;

use App\Entity\User;
use App\Enum\Onboarding\Goal;
use App\Enum\Onboarding\Level;
use App\Repository\Onboarding\AnswerRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * What a player answered in the guided tour (docs/ONBOARDING.md): a declared level and a goal, one
 * row per user, written by {@see AnswerRepository::save()} (upsert).
 */
#[ORM\Entity(repositoryClass: AnswerRepository::class)]
#[ORM\Table(name: 'onboarding_answer')]
class Answer
{
    #[ORM\Id]
    #[ORM\OneToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 16, nullable: true, enumType: Level::class)]
    private ?Level $level = null;

    #[ORM\Column(length: 16, nullable: true, enumType: Goal::class)]
    private ?Goal $goal = null;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(User $user, \DateTimeImmutable $updatedAt)
    {
        $this->user = $user;
        $this->updatedAt = $updatedAt;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getLevel(): ?Level
    {
        return $this->level;
    }

    public function getGoal(): ?Goal
    {
        return $this->goal;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
