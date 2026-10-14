<?php

declare(strict_types=1);

namespace App\ApiResource\Shop;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Post;
use App\State\Shop\PurchaseProcessor;
use App\State\Shop\StoreProvider;

/**
 * The store (docs/SHOP.md): the signed-in player's crowns, the offers, the carousel, the inventory,
 * how crowns are earned.
 * A purchase answers with the store as it stands after it.
 *
 * @phpstan-import-type View from \App\Shop\Catalog\StoreView
 * @phpstan-import-type OfferView from \App\Shop\Catalog\StoreView
 * @phpstan-import-type SlideView from \App\Shop\Catalog\StoreView
 * @phpstan-import-type FreezeView from \App\Shop\Inventory\Inventory
 * @phpstan-import-type EarningView from \App\Shop\Catalog\StoreView
 */
#[ApiResource(
    shortName: 'Shop',
    normalizationContext: ['skip_null_values' => false],
    operations: [
        new Get(uriTemplate: '/shop', provider: StoreProvider::class),
        new Post(uriTemplate: '/shop/purchases', status: 200, input: PurchaseInput::class, read: false, processor: PurchaseProcessor::class),
    ],
)]
final class Store
{
    public int $balance = 0;
    /** @var list<OfferView> */
    public array $offers = [];
    /** @var list<SlideView> */
    public array $slides = [];
    /** @var array<string, int> quantity by item type */
    public array $inventory = [];
    /** @var list<FreezeView> */
    public array $freezes = [];
    /**
     * The reward rule and the paid minutes left today (§ 4 bis).
     *
     * @var EarningView|null
     */
    public ?array $earning = null;

    /**
     * @param View $view
     */
    public static function of(array $view): self
    {
        $store = new self();
        $store->balance = $view['balance'];
        $store->offers = $view['offers'];
        $store->slides = $view['slides'];
        $store->inventory = $view['inventory'];
        $store->freezes = $view['freezes'];
        $store->earning = $view['earning'];

        return $store;
    }
}
