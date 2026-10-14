<?php

declare(strict_types=1);

namespace App\Entity\Shop;

use App\Enum\Shop\ItemType;
use App\Enum\Shop\Section;
use App\Repository\Shop\OfferRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * An offer of the store (docs/SHOP.md): one item or a pack of coded items, a price in crowns, a
 * sale window, a suspension. A cosmetic "coming soon" is shown, never sold. `slug` names the
 * default offers (created by the migration); an admin's offers get one too.
 *
 * @phpstan-type Contents list<array{item: string, quantity: int}>
 */
#[ORM\Entity(repositoryClass: OfferRepository::class)]
#[ORM\Table(name: 'shop_offer')]
#[ORM\UniqueConstraint(name: 'uniq_shop_offer_slug', columns: ['slug'])]
#[ORM\Index(name: 'idx_shop_offer_section', columns: ['section', 'position'])]
class Offer
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(length: 40, options: ['charset' => 'ascii', 'collation' => 'ascii_bin'])]
    private string $slug;

    #[ORM\Column(length: 16, enumType: Section::class)]
    private Section $section;

    #[ORM\Column(length: 80)]
    private string $name;

    /** A short line under the name (a freeze's "Le week-end"). */
    #[ORM\Column(length: 80, nullable: true)]
    private ?string $subtitle;

    #[ORM\Column(length: 255)]
    private string $description;

    /** @var Contents */
    #[ORM\Column(type: Types::JSON)]
    private array $contents;

    #[ORM\Column(options: ['unsigned' => true])]
    private int $price;

    /** The struck price (a pack's items bought one by one, or before a sale). */
    #[ORM\Column(nullable: true, options: ['unsigned' => true])]
    private ?int $wasPrice;

    #[ORM\Column(type: Types::SMALLINT, options: ['default' => 0])]
    private int $position;

    /** On sale from (UTC); null: always. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $startsAt = null;

    /** On sale until (UTC, excluded); null: always. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $endsAt = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $suspended = false;

    /** Shown as "Bientôt", never sold. */
    #[ORM\Column(options: ['default' => false])]
    private bool $comingSoon = false;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    /**
     * @param Contents $contents
     */
    public function __construct(string $slug, Section $section, string $name, ?string $subtitle, string $description, array $contents, int $price, ?int $wasPrice, int $position, \DateTimeImmutable $now)
    {
        $this->id = Uuid::v7();
        $this->slug = $slug;
        $this->section = $section;
        $this->name = $name;
        $this->subtitle = $subtitle;
        $this->description = $description;
        $this->contents = $contents;
        $this->price = $price;
        $this->wasPrice = $wasPrice;
        $this->position = $position;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function getSection(): Section
    {
        return $this->section;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getSubtitle(): ?string
    {
        return $this->subtitle;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    /**
     * @return array<string, int> quantity by item type value
     */
    public function getItems(): array
    {
        $items = [];
        foreach ($this->contents as $line) {
            if (null !== ItemType::tryFrom($line['item']) && $line['quantity'] > 0) {
                $items[$line['item']] = ($items[$line['item']] ?? 0) + $line['quantity'];
            }
        }

        return $items;
    }

    /**
     * @return Contents
     */
    public function getContents(): array
    {
        return $this->contents;
    }

    public function getPrice(): int
    {
        return $this->price;
    }

    public function getWasPrice(): ?int
    {
        return $this->wasPrice;
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

    public function isComingSoon(): bool
    {
        return $this->comingSoon;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /** Shown in the store at $now: not suspended, within its window. */
    public function isListed(\DateTimeImmutable $now): bool
    {
        return !$this->suspended
            && (null === $this->startsAt || $this->startsAt <= $now)
            && (null === $this->endsAt || $now < $this->endsAt);
    }

    /** Listed, not "coming soon", with something inside. */
    public function isOnSale(\DateTimeImmutable $now): bool
    {
        return $this->isListed($now) && !$this->comingSoon && [] !== $this->getItems();
    }

    /**
     * @param Contents $contents
     */
    public function update(Section $section, string $name, ?string $subtitle, string $description, array $contents, int $price, ?int $wasPrice, int $position, ?\DateTimeImmutable $startsAt, ?\DateTimeImmutable $endsAt, bool $suspended, bool $comingSoon, \DateTimeImmutable $now): void
    {
        $this->section = $section;
        $this->name = $name;
        $this->subtitle = $subtitle;
        $this->description = $description;
        $this->contents = $contents;
        $this->price = $price;
        $this->wasPrice = $wasPrice;
        $this->position = $position;
        $this->startsAt = $startsAt;
        $this->endsAt = $endsAt;
        $this->suspended = $suspended;
        $this->comingSoon = $comingSoon;
        $this->updatedAt = $now;
    }
}
