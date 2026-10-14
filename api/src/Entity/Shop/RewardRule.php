<?php

declare(strict_types=1);

namespace App\Entity\Shop;

use App\Entity\User;
use App\Shop\Reward\Rule;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * What a completed session earns (docs/SHOP.md, § 4 bis), as an admin set it: a single row (id 1),
 * missing until the first change (the defaults of {@see Rule} apply). Read and written in SQL by
 * {@see \App\Shop\Reward\RewardRules}.
 */
#[ORM\Entity]
#[ORM\Table(name: 'shop_reward_rule')]
class RewardRule
{
    public const ID = 1;

    #[ORM\Id]
    #[ORM\Column(type: Types::SMALLINT, options: ['unsigned' => true])]
    private int $id = self::ID;

    #[ORM\Column(options: ['unsigned' => true])]
    private int $crownsPerHour;

    #[ORM\Column(type: Types::SMALLINT, options: ['unsigned' => true])]
    private int $perfectPercent;

    #[ORM\Column(type: Types::SMALLINT, options: ['unsigned' => true])]
    private int $nearPercent;

    #[ORM\Column(type: Types::SMALLINT, options: ['unsigned' => true])]
    private int $missedPercent;

    #[ORM\Column(type: Types::SMALLINT, options: ['unsigned' => true])]
    private int $dailyMinutes;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $updatedBy = null;

    public function __construct(Rule $rule, \DateTimeImmutable $updatedAt)
    {
        $this->crownsPerHour = $rule->crownsPerHour;
        $this->perfectPercent = $rule->perfectPercent;
        $this->nearPercent = $rule->nearPercent;
        $this->missedPercent = $rule->missedPercent;
        $this->dailyMinutes = $rule->dailyMinutes;
        $this->updatedAt = $updatedAt;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function rule(): Rule
    {
        return new Rule($this->crownsPerHour, $this->perfectPercent, $this->nearPercent, $this->missedPercent, $this->dailyMinutes);
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getUpdatedBy(): ?User
    {
        return $this->updatedBy;
    }
}
