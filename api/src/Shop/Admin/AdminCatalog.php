<?php

declare(strict_types=1);

namespace App\Shop\Admin;

use App\Entity\Shop\Offer;
use App\Entity\Shop\Slide;
use App\Repository\Shop\OfferRepository;
use App\Repository\Shop\SlideRepository;
use App\Shop\Catalog\StoreView;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;

/**
 * The whole catalogue for the admin (docs/SHOP.md): every offer (suspended, past or future ones
 * too) with its sales, and every slide.
 *
 * The store's view of each offer, plus position, startsAt, suspended, listed, onSale, sales, crowns.
 *
 * @phpstan-type AdminOffer array<string, mixed>
 * @phpstan-type AdminSlide array{id: string, offerId: string, offerName: string, tag: string, image: string, position: int, startsAt: string|null, endsAt: string|null, suspended: bool, shown: bool}
 * @phpstan-type Catalog array{offers: list<AdminOffer>, slides: list<AdminSlide>}
 */
final readonly class AdminCatalog
{
    public function __construct(
        private OfferRepository $offers,
        private SlideRepository $slides,
        private Connection $connection,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return Catalog
     */
    public function view(): array
    {
        $now = $this->clock->now();
        $sales = [];
        foreach ($this->connection->fetchAllAssociative(
            "SELECT offer_id, COUNT(*) AS sales, -SUM(delta) AS crowns FROM shop_ledger_entry WHERE kind = 'purchase' AND offer_id IS NOT NULL GROUP BY offer_id",
        ) as $row) {
            if (\is_string($row['offer_id'])) {
                $sales[$row['offer_id']] = [is_numeric($row['sales']) ? (int) $row['sales'] : 0, is_numeric($row['crowns']) ? (int) $row['crowns'] : 0];
            }
        }

        return [
            'offers' => array_map(static fn (Offer $offer): array => StoreView::offer($offer) + [
                'position' => $offer->getPosition(),
                'startsAt' => $offer->getStartsAt()?->format(\DATE_ATOM),
                'suspended' => $offer->isSuspended(),
                'listed' => $offer->isListed($now),
                'onSale' => $offer->isOnSale($now),
                'sales' => $sales[$offer->getId()->toBinary()][0] ?? 0,
                'crowns' => $sales[$offer->getId()->toBinary()][1] ?? 0,
            ], $this->offers->allInOrder()),
            'slides' => array_map(static fn (Slide $slide): array => [
                'id' => $slide->getId()->toRfc4122(),
                'offerId' => $slide->getOffer()->getId()->toRfc4122(),
                'offerName' => $slide->getOffer()->getName(),
                'tag' => $slide->getTag(),
                'image' => $slide->getImage(),
                'position' => $slide->getPosition(),
                'startsAt' => $slide->getStartsAt()?->format(\DATE_ATOM),
                'endsAt' => $slide->getEndsAt()?->format(\DATE_ATOM),
                'suspended' => $slide->isSuspended(),
                'shown' => $slide->isShown($now),
            ], $this->slides->allInOrder()),
        ];
    }
}
