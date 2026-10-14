<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shop;

use App\Entity\Shop\Offer;
use App\Enum\Shop\ItemType;
use App\Enum\Shop\Section;
use App\Shop\Catalog\DefaultCatalog;
use PHPUnit\Framework\TestCase;

final class CatalogTest extends TestCase
{
    public function testTheItemsKnowTheirFreezeDaysAndModule(): void
    {
        self::assertSame([1, 2, 7, 14], array_map(
            static fn (ItemType $item): ?int => $item->freezeDays(),
            [ItemType::StreakFreeze1, ItemType::StreakFreeze2, ItemType::StreakFreeze7, ItemType::StreakFreeze14],
        ));
        self::assertNull(ItemType::PuzzleStreakPass->freezeDays());
        self::assertSame(['puzzles', 'blindfold', 'coordinates', null], array_map(
            static fn (ItemType $item): ?string => $item->module(),
            [ItemType::PuzzlePardon, ItemType::BlindfoldPardon, ItemType::CoordinatesPardon, ItemType::PuzzleStreakHelp],
        ));
    }

    public function testTheDefaultCatalogueIsConsistent(): void
    {
        $slugs = array_column(DefaultCatalog::OFFERS, 0);
        $sections = array_map(static fn (Section $section): string => $section->value, Section::cases());
        $items = array_map(static fn (ItemType $item): string => $item->value, ItemType::cases());
        self::assertSame($slugs, array_unique($slugs));
        foreach (DefaultCatalog::OFFERS as [$slug, $section, , , , $contents, $price, $was, , $soon]) {
            self::assertContains($section, $sections, $slug);
            foreach ($contents as $line) {
                self::assertContains($line['item'], $items, $slug);
            }
            self::assertSame($soon, [] === $contents, $slug.': only a cosmetic coming soon is empty');
            if (null !== $was) {
                self::assertGreaterThan($price, $was, $slug);
            }
        }
        foreach (DefaultCatalog::SLIDES as [$slug]) {
            self::assertContains($slug, $slugs);
        }
        // Every coded item is on sale somewhere.
        $sold = [];
        foreach (DefaultCatalog::OFFERS as $offer) {
            foreach ($offer[5] as $line) {
                $sold[$line['item']] = true;
            }
        }
        self::assertEqualsCanonicalizing($items, array_keys($sold));
    }

    public function testAnOfferIsOnSaleWithinItsWindowUnlessSuspendedOrComingSoon(): void
    {
        $now = new \DateTimeImmutable('2026-10-13 12:00:00');
        $offer = new Offer('test', Section::Packs, 'Test', null, 'Test', [['item' => 'puzzle_pardon', 'quantity' => 2], ['item' => 'unknown', 'quantity' => 1]], 50, null, 1, $now);
        self::assertSame(['puzzle_pardon' => 2], $offer->getItems(), 'an unknown item is ignored');
        self::assertTrue($offer->isOnSale($now));

        $update = static fn (?string $from, ?string $to, bool $suspended, bool $soon) => $offer->update(
            Section::Packs, 'Test', null, 'Test', $offer->getContents(), 50, null, 1,
            null === $from ? null : new \DateTimeImmutable($from), null === $to ? null : new \DateTimeImmutable($to), $suspended, $soon, $now,
        );
        $update('2026-10-14', null, false, false);
        self::assertFalse($offer->isListed($now), 'not yet');
        $update(null, '2026-10-13 12:00:00', false, false);
        self::assertFalse($offer->isListed($now), 'the end is excluded');
        $update('2026-10-13', '2026-10-14', false, false);
        self::assertTrue($offer->isOnSale($now));
        $update(null, null, true, false);
        self::assertFalse($offer->isListed($now), 'suspended');
        $update(null, null, false, true);
        self::assertTrue($offer->isListed($now));
        self::assertFalse($offer->isOnSale($now), 'coming soon: shown, never sold');
    }
}
