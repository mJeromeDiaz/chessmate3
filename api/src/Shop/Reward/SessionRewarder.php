<?php

declare(strict_types=1);

namespace App\Shop\Reward;

use App\Entity\Training\Run;
use App\Entity\Training\Session;
use App\Enum\Shop\LedgerKind;
use App\Enum\Shop\RewardTier;
use App\Enum\Training\Module;
use App\Shop\Wallet\Wallets;
use App\Training\Session\SessionRewardInterface;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Uid\Uuid;

/**
 * Crowns earned by completed sessions (docs/SHOP.md, § 4 bis): credited when the session closes
 * (its chest only reveals them), capped by the paid minutes of the player's local day, the chest
 * shown once. Plain SQL inside the caller's transaction; the wallet lock serializes a player's
 * rewards, so the day's cap holds.
 *
 * @phpstan-import-type RewardView from SessionRewardInterface
 */
final readonly class SessionRewarder implements SessionRewardInterface
{
    public function __construct(
        private Connection $connection,
        private Wallets $wallets,
        private RewardRules $rules,
    ) {
    }

    public function grant(Session $session, array $runs, \DateTimeImmutable $now): void
    {
        $rewarded = [];
        foreach ($runs as $run) {
            $summary = $run->getSummary();
            if (Module::Free === $run->getModule() || null === $summary) {
                continue;
            }
            $rewarded[] = [
                'durationMs' => self::int($summary['durationMs'] ?? 0),
                'itemCount' => self::int($summary['itemCount'] ?? 0),
                'successCount' => self::int($summary['successCount'] ?? 0),
            ];
        }
        $tally = RewardCalculator::tally($rewarded);
        if (0 === $tally['itemCount']) {
            return;
        }

        $user = $session->getUser();
        $userId = $user->getId();
        $rule = $this->rules->current();
        $day = $now->setTimezone($user->getDateTimeZone())->format('Y-m-d');
        $balance = $this->wallets->lock($userId, $now);
        $paid = RewardCalculator::capped($tally['paidSeconds'], $this->usedOn($userId, $day), $rule);
        $tier = RewardTier::of($tally['successCount'], $tally['itemCount']);
        $crowns = RewardCalculator::crowns($paid, $tier, $rule);
        $at = $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');

        $inserted = $this->connection->executeStatement(
            'INSERT IGNORE INTO shop_session_reward (id, user_id, session_id, local_date, item_count, success_count, paid_seconds, capped, tier, crowns, created_at, opened_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                Uuid::v7()->toBinary(), $userId->toBinary(), $session->getId()->toBinary(), $day,
                min($tally['itemCount'], 65_535), min($tally['successCount'], 65_535), $paid, $paid < $tally['paidSeconds'],
                $tier->value, $crowns, $at,
                // Nothing to reveal: no chest.
                0 === $crowns ? $at : null,
            ],
            [
                ParameterType::BINARY, ParameterType::BINARY, ParameterType::BINARY, ParameterType::STRING,
                ParameterType::INTEGER, ParameterType::INTEGER, ParameterType::INTEGER, ParameterType::BOOLEAN,
                ParameterType::STRING, ParameterType::INTEGER, ParameterType::STRING, ParameterType::STRING,
            ],
        );
        if (0 === $inserted || 0 === $crowns) {
            return;
        }
        $title = '' === $session->getTitle() ? 'Session' : 'Session « '.$session->getTitle().' »';
        $this->wallets->move($userId, $balance, $crowns, LedgerKind::Reward, ['reason' => mb_substr($title, 0, 255)], $now);
    }

    public function viewsOf(array $sessions): array
    {
        if ([] === $sessions) {
            return [];
        }
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, session_id, crowns, paid_seconds, tier, capped, opened_at FROM shop_session_reward WHERE session_id IN (?)',
            [array_map(static fn (Session $session): string => $session->getId()->toBinary(), $sessions)],
            [ArrayParameterType::BINARY],
        );
        $views = [];
        foreach ($rows as $row) {
            $views[Uuid::fromBinary(self::string($row['session_id']))->toRfc4122()] = self::view($row);
        }

        return $views;
    }

    /**
     * Opens a chest of the player (idempotent).
     *
     * @return array{reward: RewardView, balance: int}|null null when it is not the player's
     */
    public function open(Uuid $userId, Uuid $rewardId, \DateTimeImmutable $now): ?array
    {
        $this->connection->executeStatement(
            'UPDATE shop_session_reward SET opened_at = ? WHERE id = ? AND user_id = ? AND opened_at IS NULL',
            [$now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'), $rewardId->toBinary(), $userId->toBinary()],
            [ParameterType::STRING, ParameterType::BINARY, ParameterType::BINARY],
        );
        $row = $this->connection->fetchAssociative(
            'SELECT id, session_id, crowns, paid_seconds, tier, capped, opened_at FROM shop_session_reward WHERE id = ? AND user_id = ?',
            [$rewardId->toBinary(), $userId->toBinary()],
            [ParameterType::BINARY, ParameterType::BINARY],
        );

        return false === $row ? null : ['reward' => self::view($row), 'balance' => $this->wallets->balance($userId)];
    }

    /**
     * Paid minutes left today for the player (the store's "how to earn").
     */
    public function minutesLeftToday(Uuid $userId, \DateTimeZone $timezone, \DateTimeImmutable $now): int
    {
        $used = $this->usedOn($userId, $now->setTimezone($timezone)->format('Y-m-d'));

        return intdiv(RewardCalculator::capped(\PHP_INT_MAX, $used, $this->rules->current()), 60);
    }

    /** Seconds already paid on a local day. */
    private function usedOn(Uuid $userId, string $day): int
    {
        return self::int($this->connection->fetchOne(
            'SELECT COALESCE(SUM(paid_seconds), 0) FROM shop_session_reward WHERE user_id = ? AND local_date = ?',
            [$userId->toBinary(), $day],
            [ParameterType::BINARY, ParameterType::STRING],
        ));
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return RewardView
     */
    private static function view(array $row): array
    {
        return [
            'id' => Uuid::fromBinary(self::string($row['id']))->toRfc4122(),
            'crowns' => self::int($row['crowns']),
            'paidMinutes' => (int) round(self::int($row['paid_seconds']) / 60),
            'tier' => self::string($row['tier']),
            'capped' => (bool) $row['capped'],
            'opened' => null !== $row['opened_at'],
        ];
    }

    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private static function string(mixed $value): string
    {
        return \is_string($value) ? $value : '';
    }
}
