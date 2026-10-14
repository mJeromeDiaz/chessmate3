<?php

declare(strict_types=1);

namespace App\Shop\Reward;

use App\Entity\Shop\RewardRule;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Uid\Uuid;

/**
 * The reward rule as the admin set it (docs/SHOP.md, § 4 bis): the single `shop_reward_rule` row,
 * or the defaults while no admin changed them.
 *
 * @phpstan-type RuleView array{crownsPerHour: int, perfectPercent: int, nearPercent: int, missedPercent: int, dailyMinutes: int, updatedAt: string|null, updatedBy: string|null}
 */
final readonly class RewardRules
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function current(): Rule
    {
        $row = $this->row();

        return null === $row ? new Rule() : new Rule(
            self::int($row['crowns_per_hour']),
            self::int($row['perfect_percent']),
            self::int($row['near_percent']),
            self::int($row['missed_percent']),
            self::int($row['daily_minutes']),
        );
    }

    /**
     * The rule for the admin, with who changed it last.
     *
     * @return RuleView
     */
    public function view(): array
    {
        $row = $this->row();

        return $this->current()->toArray() + [
            'updatedAt' => \is_string($row['updated_at'] ?? null) ? (new \DateTimeImmutable($row['updated_at'], new \DateTimeZone('UTC')))->format(\DATE_ATOM) : null,
            'updatedBy' => \is_string($row['admin'] ?? null) ? $row['admin'] : null,
        ];
    }

    public function save(Rule $rule, Uuid $adminId, \DateTimeImmutable $now): void
    {
        $this->connection->executeStatement(
            'INSERT INTO shop_reward_rule (id, crowns_per_hour, perfect_percent, near_percent, missed_percent, daily_minutes, updated_at, updated_by_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE crowns_per_hour = VALUES(crowns_per_hour), perfect_percent = VALUES(perfect_percent),
                near_percent = VALUES(near_percent), missed_percent = VALUES(missed_percent), daily_minutes = VALUES(daily_minutes),
                updated_at = VALUES(updated_at), updated_by_id = VALUES(updated_by_id)',
            [
                RewardRule::ID, $rule->crownsPerHour, $rule->perfectPercent, $rule->nearPercent, $rule->missedPercent, $rule->dailyMinutes,
                $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'), $adminId->toBinary(),
            ],
            [
                ParameterType::INTEGER, ParameterType::INTEGER, ParameterType::INTEGER, ParameterType::INTEGER, ParameterType::INTEGER, ParameterType::INTEGER,
                ParameterType::STRING, ParameterType::BINARY,
            ],
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function row(): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT r.*, a.email AS admin FROM shop_reward_rule r LEFT JOIN app_user a ON a.id = r.updated_by_id WHERE r.id = ?',
            [RewardRule::ID],
            [ParameterType::INTEGER],
        );

        return false === $row ? null : $row;
    }

    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
