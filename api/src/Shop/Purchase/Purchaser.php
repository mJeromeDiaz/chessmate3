<?php

declare(strict_types=1);

namespace App\Shop\Purchase;

use App\Entity\User;
use App\Enum\Shop\ItemType;
use App\Enum\Shop\LedgerKind;
use App\Repository\Shop\OfferRepository;
use App\Shop\Inventory\Inventory;
use App\Shop\Wallet\Wallets;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Buys an offer (docs/SHOP.md): on sale now, paid in crowns at once, its items in the inventory,
 * all in one transaction on the locked balance. Never a negative balance.
 */
final readonly class Purchaser
{
    public const MAX_QUANTITY = 9;

    public function __construct(
        private Connection $connection,
        private OfferRepository $offers,
        private Wallets $wallets,
        private Inventory $inventory,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return int the balance left
     *
     * @throws PurchaseRefusal
     */
    public function buy(User $user, Uuid $offerId, int $quantity): int
    {
        $now = $this->clock->now();
        $offer = $this->offers->find($offerId) ?? throw new PurchaseRefusal(PurchaseRefusal::NOT_FOUND, 'Offer not found.');
        if (!$offer->isOnSale($now)) {
            throw new PurchaseRefusal(PurchaseRefusal::NOT_ON_SALE, 'This offer is not on sale.');
        }
        $total = $offer->getPrice() * $quantity;
        $userId = $user->getId();

        // DBAL transaction: a refusal rolls back without closing the entity manager.
        return $this->connection->transactional(function () use ($offer, $quantity, $total, $userId, $now): int {
            $balance = $this->wallets->lock($userId, $now);
            if ($total > $balance) {
                throw new PurchaseRefusal(PurchaseRefusal::INSUFFICIENT, \sprintf('%d crowns missing.', $total - $balance));
            }
            $left = $this->wallets->move($userId, $balance, -$total, LedgerKind::Purchase, [
                'offerId' => $offer->getId(),
                'offerName' => $offer->getName(),
                'quantity' => $quantity,
            ], $now);
            foreach ($offer->getItems() as $item => $count) {
                $this->inventory->add($userId, ItemType::from($item), $count * $quantity, $now);
            }

            return $left;
        });
    }
}
