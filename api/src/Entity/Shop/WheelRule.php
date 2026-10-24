<?php

declare(strict_types=1);

namespace App\Entity\Shop;

use App\Entity\User;
use App\Shop\Wheel\Odds;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * The gift wheel's odds (docs/SHOP.md, § 4 ter), as an admin set them: a single row (id 1),
 * missing until the first change (the defaults of {@see Odds} apply). Read and written in SQL by
 * {@see \App\Shop\Wheel\WheelRules}; the entity maps the schema.
 */
#[ORM\Entity]
#[ORM\Table(name: 'shop_wheel_rule')]
class WheelRule
{
    public const ID = 1;

    #[ORM\Id]
    #[ORM\Column(type: Types::SMALLINT, options: ['unsigned' => true])]
    private int $id = self::ID;

    /** The prizes' weights (their chances: weight / sum of the weights). */
    #[ORM\Column(type: Types::SMALLINT, options: ['unsigned' => true])]
    private int $weightC5;

    #[ORM\Column(type: Types::SMALLINT, options: ['unsigned' => true])]
    private int $weightC30;

    #[ORM\Column(type: Types::SMALLINT, options: ['unsigned' => true])]
    private int $weightC300;

    #[ORM\Column(type: Types::SMALLINT, options: ['unsigned' => true])]
    private int $weightGift;

    #[ORM\Column(type: Types::SMALLINT, options: ['unsigned' => true])]
    private int $weightLose;

    /** A gift's chance to be a streak freeze (the costume otherwise), in percent. */
    #[ORM\Column(type: Types::SMALLINT, options: ['unsigned' => true])]
    private int $freezePercent;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $updatedBy = null;

    public function __construct(Odds $odds, \DateTimeImmutable $updatedAt)
    {
        $this->weightC5 = $odds->c5;
        $this->weightC30 = $odds->c30;
        $this->weightC300 = $odds->c300;
        $this->weightGift = $odds->gift;
        $this->weightLose = $odds->lose;
        $this->freezePercent = $odds->freezePercent;
        $this->updatedAt = $updatedAt;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function odds(): Odds
    {
        return new Odds($this->weightC5, $this->weightC30, $this->weightC300, $this->weightGift, $this->weightLose, $this->freezePercent);
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
