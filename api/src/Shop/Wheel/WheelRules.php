<?php

declare(strict_types=1);

namespace App\Shop\Wheel;

use App\Entity\Shop\WheelRule;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Uid\Uuid;

/**
 * The gift wheel's odds set in the admin (docs/SHOP.md, § 4 ter, `shop_wheel_rule`), the defaults
 * until the first change; they apply to the next spins.
 *
 * @phpstan-type OddsView array{c5: int, c30: int, c300: int, gift: int, lose: int, freezePercent: int, updatedAt: string|null, updatedBy: string|null}
 */
final readonly class WheelRules
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function current(): Odds
    {
        $row = $this->row();

        return null === $row ? new Odds() : new Odds(
            self::int($row['weight_c5']),
            self::int($row['weight_c30']),
            self::int($row['weight_c300']),
            self::int($row['weight_gift']),
            self::int($row['weight_lose']),
            self::int($row['freeze_percent']),
        );
    }

    /**
     * The odds for the admin, with who changed them last.
     *
     * @return OddsView
     */
    public function view(): array
    {
        $row = $this->row();

        return $this->current()->toArray() + [
            'updatedAt' => \is_string($row['updated_at'] ?? null) ? (new \DateTimeImmutable($row['updated_at'], new \DateTimeZone('UTC')))->format(\DATE_ATOM) : null,
            'updatedBy' => \is_string($row['admin'] ?? null) ? $row['admin'] : null,
        ];
    }

    public function save(Odds $odds, Uuid $adminId, \DateTimeImmutable $now): void
    {
        $this->connection->executeStatement(
            'INSERT INTO shop_wheel_rule (id, weight_c5, weight_c30, weight_c300, weight_gift, weight_lose, freeze_percent, updated_at, updated_by_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE weight_c5 = VALUES(weight_c5), weight_c30 = VALUES(weight_c30), weight_c300 = VALUES(weight_c300),
                weight_gift = VALUES(weight_gift), weight_lose = VALUES(weight_lose), freeze_percent = VALUES(freeze_percent),
                updated_at = VALUES(updated_at), updated_by_id = VALUES(updated_by_id)',
            [
                WheelRule::ID, $odds->c5, $odds->c30, $odds->c300, $odds->gift, $odds->lose, $odds->freezePercent,
                $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'), $adminId->toBinary(),
            ],
            [
                ParameterType::INTEGER, ParameterType::INTEGER, ParameterType::INTEGER, ParameterType::INTEGER, ParameterType::INTEGER, ParameterType::INTEGER,
                ParameterType::INTEGER, ParameterType::STRING, ParameterType::BINARY,
            ],
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function row(): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT r.*, a.email AS admin FROM shop_wheel_rule r LEFT JOIN app_user a ON a.id = r.updated_by_id WHERE r.id = ?',
            [WheelRule::ID],
            [ParameterType::INTEGER],
        );

        return false === $row ? null : $row;
    }

    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
