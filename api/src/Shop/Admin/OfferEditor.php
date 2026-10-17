<?php

declare(strict_types=1);

namespace App\Shop\Admin;

use App\Entity\Shop\Offer;
use App\Enum\Shop\ItemType;
use App\Enum\Shop\Section;
use App\Repository\Shop\OfferRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * The admin's offers (docs/SHOP.md): an offer combines coded items; its slug never changes (the
 * default offers are known by it). No deletion: a suspension takes an offer off the store and
 * keeps the purchases' history.
 *
 * @phpstan-import-type Contents from Offer
 */
final readonly class OfferEditor
{
    public const MAX_PRICE = 100_000;
    public const MAX_ITEM_QUANTITY = 99;

    public function __construct(
        private OfferRepository $offers,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param array<mixed> $data
     *
     * @throws InvalidPayload
     */
    public function create(array $data): Offer
    {
        $payload = new Payload($data);
        $slug = $payload->string('slug', 40);
        if (null !== $slug && 1 !== preg_match('/^[a-z0-9_]{2,40}$/', $slug)) {
            $payload->fail('slug', 'shop.slug_format');
        } elseif (null !== $slug && null !== $this->offers->findOneBy(['slug' => $slug])) {
            $payload->fail('slug', 'shop.slug_taken');
        }
        $fields = $this->read($payload);
        $payload->done();
        \assert(null !== $slug && null !== $fields);

        $now = $this->clock->now();
        $offer = new Offer($slug, $fields['section'], $fields['name'], $fields['subtitle'], $fields['description'], $fields['contents'], $fields['price'], $fields['wasPrice'], $fields['position'], $now);
        $this->apply($offer, $fields, $now);
        $this->entityManager->persist($offer);
        $this->entityManager->flush();

        return $offer;
    }

    /**
     * @param array<mixed> $data
     *
     * @throws InvalidPayload
     */
    public function update(Uuid $id, array $data): ?Offer
    {
        $offer = $this->offers->find($id);
        if (null === $offer) {
            return null;
        }
        $payload = new Payload($data);
        $fields = $this->read($payload);
        $payload->done();
        \assert(null !== $fields);
        $this->apply($offer, $fields, $this->clock->now());
        $this->entityManager->flush();

        return $offer;
    }

    /**
     * @param array{section: Section, name: string, subtitle: string|null, description: string, contents: Contents, price: int, wasPrice: int|null, position: int, startsAt: \DateTimeImmutable|null, endsAt: \DateTimeImmutable|null, suspended: bool, comingSoon: bool} $fields
     */
    private function apply(Offer $offer, array $fields, \DateTimeImmutable $now): void
    {
        $offer->update($fields['section'], $fields['name'], $fields['subtitle'], $fields['description'], $fields['contents'], $fields['price'], $fields['wasPrice'], $fields['position'], $fields['startsAt'], $fields['endsAt'], $fields['suspended'], $fields['comingSoon'], $now);
    }

    /**
     * @return array{section: Section, name: string, subtitle: string|null, description: string, contents: Contents, price: int, wasPrice: int|null, position: int, startsAt: \DateTimeImmutable|null, endsAt: \DateTimeImmutable|null, suspended: bool, comingSoon: bool}|null null when a field is wrong
     */
    private function read(Payload $payload): ?array
    {
        $sectionValue = $payload->string('section', 16);
        $section = null === $sectionValue ? null : Section::tryFrom($sectionValue);
        if (null !== $sectionValue && null === $section) {
            $payload->fail('section', 'shop.unknown_section');
        }
        $name = $payload->string('name', 80);
        $subtitle = $payload->string('subtitle', 80, false);
        $description = $payload->string('description', 255);
        $price = $payload->int('price', 0, self::MAX_PRICE);
        $wasPrice = $payload->int('wasPrice', 1, self::MAX_PRICE, false);
        if (null !== $price && null !== $wasPrice && $wasPrice <= $price) {
            $payload->fail('wasPrice', 'shop.was_price');
        }
        $position = $payload->int('position', -999, 999, false) ?? 0;
        $startsAt = $payload->instant('startsAt');
        $endsAt = $payload->instant('endsAt');
        if (null !== $startsAt && null !== $endsAt && $endsAt <= $startsAt) {
            $payload->fail('endsAt', 'shop.ends_after_start');
        }
        $comingSoon = $payload->bool('comingSoon');

        /** @var Contents $contents */
        $contents = [];
        $seen = [];
        foreach ($payload->list('contents') as $i => $line) {
            $item = \is_array($line) && \is_string($line['item'] ?? null) ? ItemType::tryFrom($line['item']) : null;
            $quantity = \is_array($line) ? ($line['quantity'] ?? null) : null;
            if (null === $item) {
                $payload->fail("contents[$i].item", 'shop.unknown_item');
            } elseif (!\is_int($quantity) || $quantity < 1 || $quantity > self::MAX_ITEM_QUANTITY) {
                $payload->fail("contents[$i].quantity", 'shop.quantity', ['%max%' => self::MAX_ITEM_QUANTITY]);
            } elseif (isset($seen[$item->value])) {
                $payload->fail("contents[$i].item", 'shop.duplicate_item');
            } else {
                $seen[$item->value] = true;
                $contents[] = ['item' => $item->value, 'quantity' => $quantity];
            }
        }
        if ([] === $contents && !$comingSoon) {
            $payload->fail('contents', 'shop.empty_offer');
        }

        if (null === $section || null === $name || null === $description || null === $price) {
            return null;
        }

        return [
            'section' => $section,
            'name' => $name,
            'subtitle' => $subtitle,
            'description' => $description,
            'contents' => $contents,
            'price' => $price,
            'wasPrice' => $wasPrice,
            'position' => $position,
            'startsAt' => $startsAt,
            'endsAt' => $endsAt,
            'suspended' => $payload->bool('suspended'),
            'comingSoon' => $comingSoon,
        ];
    }
}
