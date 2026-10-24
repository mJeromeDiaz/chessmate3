<?php

declare(strict_types=1);

namespace App\Shop\Wheel;

use App\Entity\User;
use App\Enum\Shop\ItemType;
use App\Enum\Shop\LedgerKind;
use App\Enum\Shop\WheelGift;
use App\Enum\Shop\WheelPrize;
use App\Enum\Shop\WheelStatus;
use App\Shop\Inventory\Inventory;
use App\Shop\Wallet\Wallets;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Psr\Clock\ClockInterface;
use Random\Randomizer;
use Symfony\Component\Uid\Uuid;

/**
 * The gift wheel (docs/SHOP.md, § 4 ter): the day's spin (offered with the streak's announcement,
 * that local day only) or a ticket's. The server draws the prize from the admin's odds and credits
 * it at once: crystals (ledger `reward`, « Roue des cadeaux »), a 1-day streak freeze, or the
 * costume (a message only). DBAL transaction: a refusal rolls back without closing the entity
 * manager.
 *
 * @phpstan-type SpinView array{id: string, prize: string, gift: string|null, crowns: int, ticket: bool, balance: int, tickets: int}
 */
final readonly class WheelSpinner
{
    public const LEDGER_REASON = 'Roue des cadeaux';

    public function __construct(
        private Connection $connection,
        private Wallets $wallets,
        private Inventory $inventory,
        private WheelRules $rules,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Spins the day's wheel ($dailyRewardId), or once more with a ticket (null).
     *
     * @return SpinView
     *
     * @throws WheelRefusal
     */
    public function spin(User $user, ?Uuid $dailyRewardId): array
    {
        $now = $this->clock->now();
        $userId = $user->getId();
        $randomizer = new Randomizer();

        return $this->connection->transactional(function () use ($user, $userId, $dailyRewardId, $randomizer, $now): array {
            if (null === $dailyRewardId) {
                if (!$this->inventory->consume($userId, ItemType::WheelTicket)) {
                    throw new WheelRefusal(WheelRefusal::NO_TICKET, 'No ticket to spin the wheel.');
                }
            } elseif (!$this->close($user, $dailyRewardId, WheelStatus::Spun, $now)) {
                throw new WheelRefusal(WheelRefusal::NO_SPIN, 'No wheel to spin today.');
            }

            $odds = $this->rules->current();
            $prize = $odds->draw($randomizer);
            $gift = WheelPrize::Gift === $prize ? $odds->gift($randomizer) : null;
            $balance = $this->wallets->lock($userId, $now);
            if ($prize->crowns() > 0) {
                $balance = $this->wallets->move($userId, $balance, $prize->crowns(), LedgerKind::Reward, ['reason' => self::LEDGER_REASON], $now);
            }
            if (WheelGift::Freeze === $gift) {
                $this->inventory->add($userId, ItemType::StreakFreeze1, 1, $now);
            }
            $id = Uuid::v7();
            $this->connection->executeStatement(
                'INSERT INTO shop_wheel_spin (id, user_id, daily_reward_id, prize, gift, crowns, ticket, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [$id->toBinary(), $userId->toBinary(), $dailyRewardId?->toBinary(), $prize->value, $gift?->value, $prize->crowns(), null === $dailyRewardId ? 1 : 0, self::utc($now)],
                [ParameterType::BINARY, ParameterType::BINARY, ParameterType::BINARY, ParameterType::STRING, ParameterType::STRING, ParameterType::INTEGER, ParameterType::INTEGER, ParameterType::STRING],
            );

            return [
                'id' => $id->toRfc4122(),
                'prize' => $prize->value,
                'gift' => $gift?->value,
                'crowns' => $prize->crowns(),
                'ticket' => null === $dailyRewardId,
                'balance' => $balance,
                'tickets' => $this->inventory->items($userId)[ItemType::WheelTicket->value] ?? 0,
            ];
        });
    }

    /**
     * « Pas aujourd'hui »: the day's wheel lost.
     *
     * @throws WheelRefusal
     */
    public function decline(User $user, Uuid $dailyRewardId): void
    {
        if (!$this->close($user, $dailyRewardId, WheelStatus::Declined, $this->clock->now())) {
            throw new WheelRefusal(WheelRefusal::NO_SPIN, 'No wheel to decline today.');
        }
    }

    /**
     * The day's wheel spun or declined, if it is still offered today; its row seen when it holds
     * no badge chest.
     */
    private function close(User $user, Uuid $dailyRewardId, WheelStatus $status, \DateTimeImmutable $now): bool
    {
        return 1 === $this->connection->executeStatement(
            "UPDATE shop_daily_reward SET wheel = ?, opened_at = IF(crowns = 0, COALESCE(opened_at, ?), opened_at)
              WHERE id = ? AND user_id = ? AND wheel = 'offered' AND local_date = ?",
            [$status->value, self::utc($now), $dailyRewardId->toBinary(), $user->getId()->toBinary(), $now->setTimezone($user->getDateTimeZone())->format('Y-m-d')],
            [ParameterType::STRING, ParameterType::STRING, ParameterType::BINARY, ParameterType::BINARY, ParameterType::STRING],
        );
    }

    private static function utc(\DateTimeImmutable $at): string
    {
        return $at->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
