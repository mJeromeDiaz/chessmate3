<?php

declare(strict_types=1);

namespace App\Arena\Unlock;

use App\Arena\Roster\Availability;
use App\Entity\User;
use App\Enum\Arena\BossStatus;
use App\Repository\Arena\UnlockRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Uid\Uuid;

/**
 * The bosses arriving in the player's arena (docs/ARENA.md, § 5): each one available is written
 * once (INSERT IGNORE: two reads at once write it once), and waits to be announced by the animated
 * reveal until the client says it was shown.
 */
final readonly class Unlocks
{
    public function __construct(
        private Availability $availability,
        private UnlockRepository $unlocks,
        private Connection $connection,
    ) {
    }

    /**
     * Writes the bosses that became available.
     *
     * @param array<string, BossStatus>|null $statuses the player's, when already read
     *
     * @return list<string> the bosses not announced yet, in the roster's order
     */
    public function sync(User $user, \DateTimeImmutable $now, ?array $statuses = null): array
    {
        $statuses ??= $this->availability->of($user);
        $known = $this->unlocks->byBoss($user);
        $pending = [];
        foreach ($statuses as $boss => $status) {
            if (BossStatus::Available !== $status) {
                continue;
            }
            $unlock = $known[$boss] ?? null;
            if (null === $unlock) {
                $this->connection->executeStatement(
                    'INSERT IGNORE INTO arena_unlock (id, user_id, boss, unlocked_at, announced_at) VALUES (?, ?, ?, ?, NULL)',
                    [Uuid::v7()->toBinary(), $user->getId()->toBinary(), $boss, $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s')],
                    [ParameterType::BINARY, ParameterType::BINARY],
                );
                $pending[] = $boss;
            } elseif (null === $unlock->getAnnouncedAt()) {
                $pending[] = $boss;
            }
        }

        return $pending;
    }

    /**
     * Marks the bosses announced (idempotent; a boss never written is ignored).
     *
     * @param list<string> $bosses
     */
    public function announce(User $user, array $bosses, \DateTimeImmutable $now): void
    {
        if ([] === $bosses) {
            return;
        }
        $this->connection->executeStatement(
            'UPDATE arena_unlock SET announced_at = ? WHERE user_id = ? AND announced_at IS NULL AND boss IN (?)',
            [$now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'), $user->getId()->toBinary(), $bosses],
            [ParameterType::STRING, ParameterType::BINARY, ArrayParameterType::STRING],
        );
    }
}
