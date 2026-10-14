<?php

declare(strict_types=1);

namespace App\Entity\Shop;

use App\Repository\Shop\SlideRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A slide of the store's carousel (docs/SHOP.md): an offer, a catchline, a professor's picture, a
 * window, a suspension. Shown only while its offer is listed.
 */
#[ORM\Entity(repositoryClass: SlideRepository::class)]
#[ORM\Table(name: 'shop_slide')]
class Slide
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Offer::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Offer $offer;

    /** "OFFRE DU MOMENT" */
    #[ORM\Column(length: 40)]
    private string $tag;

    /** A professor's picture (front `src/assets/profs`, file name without extension). */
    #[ORM\Column(length: 40, options: ['charset' => 'ascii', 'collation' => 'ascii_bin'])]
    private string $image;

    #[ORM\Column(type: Types::SMALLINT, options: ['default' => 0])]
    private int $position;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $startsAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $endsAt = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $suspended = false;

    public function __construct(Offer $offer, string $tag, string $image, int $position)
    {
        $this->id = Uuid::v7();
        $this->offer = $offer;
        $this->tag = $tag;
        $this->image = $image;
        $this->position = $position;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getOffer(): Offer
    {
        return $this->offer;
    }

    public function getTag(): string
    {
        return $this->tag;
    }

    public function getImage(): string
    {
        return $this->image;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function getStartsAt(): ?\DateTimeImmutable
    {
        return $this->startsAt;
    }

    public function getEndsAt(): ?\DateTimeImmutable
    {
        return $this->endsAt;
    }

    public function isSuspended(): bool
    {
        return $this->suspended;
    }

    public function isShown(\DateTimeImmutable $now): bool
    {
        return !$this->suspended
            && (null === $this->startsAt || $this->startsAt <= $now)
            && (null === $this->endsAt || $now < $this->endsAt)
            && $this->offer->isOnSale($now);
    }

    public function update(Offer $offer, string $tag, string $image, int $position, ?\DateTimeImmutable $startsAt, ?\DateTimeImmutable $endsAt, bool $suspended): void
    {
        $this->offer = $offer;
        $this->tag = $tag;
        $this->image = $image;
        $this->position = $position;
        $this->startsAt = $startsAt;
        $this->endsAt = $endsAt;
        $this->suspended = $suspended;
    }
}
