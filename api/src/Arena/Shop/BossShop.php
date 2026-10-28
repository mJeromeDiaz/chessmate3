<?php

declare(strict_types=1);

namespace App\Arena\Shop;

use App\Arena\Game\GameRefusal;
use App\Arena\Roster\Roster;
use App\Entity\User;
use App\Enum\Shop\LedgerKind;
use App\Repository\Arena\UnlockRepository;
use App\Shop\Wallet\Wallets;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Uid\Uuid;

/**
 * Buys one of the arena's own bosses (docs/ARENA.md, § 6 bis): a path's boss is never sold. Paid in
 * crystals at once on the locked balance, never a negative one; the boss then arrives in the
 * player's arena, its arrival to show.
 */
final readonly class BossShop
{
    public function __construct(
        private Connection $connection,
        private Wallets $wallets,
        private UnlockRepository $unlocks,
    ) {
    }

    /**
     * @return GameRefusal|null null once bought
     */
    public function buy(User $user, string $character, \DateTimeImmutable $now): ?GameRefusal
    {
        $boss = Roster::find($character);
        if (null === $boss) {
            return GameRefusal::UnknownBoss;
        }
        $price = $boss->price();
        if (null === $price) {
            return GameRefusal::NotForSale;
        }
        $userId = $user->getId();

        // DBAL transaction: a refusal rolls back without closing the entity manager.
        return $this->connection->transactional(function () use ($user, $userId, $boss, $price, $now): ?GameRefusal {
            $balance = $this->wallets->lock($userId, $now);
            if (\in_array($boss->character, $this->unlocks->purchased($user), true)) {
                return GameRefusal::Owned;
            }
            if ($price > $balance) {
                return GameRefusal::Insufficient;
            }
            $this->wallets->move($userId, $balance, -$price, LedgerKind::Purchase, ['offerName' => \sprintf('Arène · %s', $boss->character), 'quantity' => 1], $now);
            $at = $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            $this->connection->executeStatement(
                'INSERT INTO arena_unlock (id, user_id, boss, unlocked_at, announced_at, purchased_at, price) VALUES (?, ?, ?, ?, NULL, ?, ?)
                 ON DUPLICATE KEY UPDATE unlocked_at = VALUES(unlocked_at), announced_at = NULL, purchased_at = VALUES(purchased_at), price = VALUES(price)',
                [Uuid::v7()->toBinary(), $userId->toBinary(), $boss->character, $at, $at, $price],
                [ParameterType::BINARY, ParameterType::BINARY, ParameterType::STRING, ParameterType::STRING, ParameterType::STRING, ParameterType::INTEGER],
            );

            return null;
        });
    }
}
