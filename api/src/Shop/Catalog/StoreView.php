<?php

declare(strict_types=1);

namespace App\Shop\Catalog;

use App\Entity\Shop\Offer;
use App\Entity\Shop\Slide;
use App\Entity\User;
use App\Enum\Shop\ItemType;
use App\Repository\Shop\OfferRepository;
use App\Repository\Shop\SlideRepository;
use App\Shop\Inventory\Inventory;
use App\Shop\Reward\RewardRules;
use App\Shop\Reward\SessionRewarder;
use App\Shop\Wallet\Wallets;
use Psr\Clock\ClockInterface;

/**
 * The store as a player sees it (docs/SHOP.md): the balance, the listed offers in order, the
 * carousel, what the player holds, how crowns are earned (§ 4 bis) and the paid minutes left today.
 *
 * @phpstan-import-type FreezeView from Inventory
 *
 * @phpstan-type OfferView array{id: string, slug: string, section: string, name: string, subtitle: string|null, description: string, items: list<array{item: string, quantity: int}>, module: string|null, price: int, wasPrice: int|null, comingSoon: bool, endsAt: string|null}
 * @phpstan-type SlideView array{id: string, offerId: string, tag: string, image: string}
 * @phpstan-type EarningView array{crownsPerHour: int, perfectPercent: int, nearPercent: int, missedPercent: int, dailyMinutes: int, minutesLeftToday: int}
 * @phpstan-type View array{balance: int, offers: list<OfferView>, slides: list<SlideView>, inventory: array<string, int>, freezes: list<FreezeView>, earning: EarningView}
 */
final readonly class StoreView
{
    public function __construct(
        private OfferRepository $offers,
        private SlideRepository $slides,
        private Wallets $wallets,
        private Inventory $inventory,
        private RewardRules $rules,
        private SessionRewarder $rewards,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return View
     */
    public function of(User $user): array
    {
        $now = $this->clock->now();
        $userId = $user->getId();

        return [
            'balance' => $this->wallets->balance($userId),
            'offers' => array_map(self::offer(...), $this->offers->listed($now)),
            'slides' => array_map(static fn (Slide $slide): array => [
                'id' => $slide->getId()->toRfc4122(),
                'offerId' => $slide->getOffer()->getId()->toRfc4122(),
                'tag' => $slide->getTag(),
                'image' => $slide->getImage(),
            ], $this->slides->shown($now)),
            'inventory' => $this->inventory->items($userId),
            'freezes' => $this->inventory->freezes($userId),
            'earning' => $this->rules->current()->toArray() + [
                'minutesLeftToday' => $this->rewards->minutesLeftToday($userId, $user->getDateTimeZone(), $now),
            ],
        ];
    }

    /**
     * @return OfferView
     */
    public static function offer(Offer $offer): array
    {
        $items = [];
        $modules = [];
        foreach ($offer->getItems() as $item => $quantity) {
            $items[] = ['item' => $item, 'quantity' => $quantity];
            $modules[] = ItemType::from($item)->module();
        }
        $modules = array_values(array_unique($modules));

        return [
            'id' => $offer->getId()->toRfc4122(),
            'slug' => $offer->getSlug(),
            'section' => $offer->getSection()->value,
            'name' => $offer->getName(),
            'subtitle' => $offer->getSubtitle(),
            'description' => $offer->getDescription(),
            'items' => $items,
            // The module tab of a module aid (all its items in one module).
            'module' => 1 === \count($modules) ? $modules[0] : null,
            'price' => $offer->getPrice(),
            'wasPrice' => $offer->getWasPrice(),
            'comingSoon' => $offer->isComingSoon(),
            'endsAt' => $offer->getEndsAt()?->format(\DATE_ATOM),
        ];
    }
}
