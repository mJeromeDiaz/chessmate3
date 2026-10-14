<?php

declare(strict_types=1);

namespace App\Shop\Wallet;

use App\Enum\Shop\LedgerKind;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Crowns given or taken back by hand (docs/SHOP.md: the admin, the demo fixtures), with a reason in
 * the ledger. Never below zero.
 */
final readonly class CrownAdjustment
{
    public function __construct(
        private Connection $connection,
        private Wallets $wallets,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param int $delta crowns given (positive) or taken back (negative), not 0
     *
     * @return int the new balance
     *
     * @throws \DomainException when taking back more than the balance
     */
    public function adjust(Uuid $userId, int $delta, ?string $reason, ?Uuid $adminId): int
    {
        if (0 === $delta) {
            throw new \InvalidArgumentException('Nothing to move.');
        }
        $now = $this->clock->now();

        return $this->connection->transactional(function () use ($userId, $delta, $reason, $adminId, $now): int {
            $balance = $this->wallets->lock($userId, $now);
            if ($balance + $delta < 0) {
                throw new \DomainException(\sprintf('Only %d crowns to take back.', $balance));
            }
            $details = ['reason' => $reason];
            if (null !== $adminId) {
                $details['adminId'] = $adminId;
            }

            return $this->wallets->move($userId, $balance, $delta, $delta > 0 ? LedgerKind::Grant : LedgerKind::Revoke, $details, $now);
        });
    }
}
