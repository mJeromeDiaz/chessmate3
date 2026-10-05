<?php

declare(strict_types=1);

namespace App\Gamification\Progress;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * Counts the rows of a query that selects one instant `at` per element (a solved puzzle, a
 * completed session…), and finds when the N-th one happened: the progress of a trophy or a quest
 * and the date of its feat. The query binds the user as `:user`.
 */
final readonly class Counter
{
    public function __construct(private Connection $connection)
    {
    }

    /**
     * @param array<string, string> $params besides `user` (binary)
     *
     * @return array{current: int, reachedAt: \DateTimeImmutable|null} current is capped at $goal
     */
    public function nth(string $sql, string $userId, int $goal, array $params = []): array
    {
        $params = [...$params, 'user' => $userId];
        $types = ['user' => ParameterType::BINARY];
        $count = $this->connection->fetchOne(\sprintf('SELECT COUNT(*) FROM (%s) x WHERE x.at IS NOT NULL', $sql), $params, $types);
        $count = is_numeric($count) ? (int) $count : 0;
        $at = $count >= $goal && $goal > 0 ? $this->connection->fetchOne(
            \sprintf('SELECT x.at FROM (%s) x WHERE x.at IS NOT NULL ORDER BY x.at LIMIT 1 OFFSET %d', $sql, $goal - 1),
            $params,
            $types,
        ) : null;

        return ['current' => min($count, $goal), 'reachedAt' => self::instant($at)];
    }

    /**
     * The number of rows, uncapped.
     *
     * @param array<string, string> $params besides `user` (binary)
     */
    public function count(string $sql, string $userId, array $params = []): int
    {
        $count = $this->connection->fetchOne(
            \sprintf('SELECT COUNT(*) FROM (%s) x WHERE x.at IS NOT NULL', $sql),
            [...$params, 'user' => $userId],
            ['user' => ParameterType::BINARY],
        );

        return is_numeric($count) ? (int) $count : 0;
    }

    /** An instant read from MySQL (UTC). */
    public static function instant(mixed $value): ?\DateTimeImmutable
    {
        return \is_string($value) ? new \DateTimeImmutable($value, new \DateTimeZone('UTC')) : null;
    }
}
