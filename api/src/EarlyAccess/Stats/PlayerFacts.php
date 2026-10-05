<?php

declare(strict_types=1);

namespace App\EarlyAccess\Stats;

use App\Entity\User;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * What the admin player list shows beside the account itself, for one page of players at once
 * (three queries, whatever the page size): sign-up method and key, last exercise and training
 * time of the last 30 days, Lichess rating.
 *
 * @phpstan-type Lichess array{username: string|null, perf: string|null, rating: int|null}
 * @phpstan-type Facts array{signupMethod: string, keyHint: string|null, lastActivityAt: \DateTimeImmutable|null, trainingMs30d: int, lichess: Lichess|null}
 */
final class PlayerFacts
{
    public const TRAINING_DAYS = 30;

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @param list<User> $users
     *
     * @return array<string, Facts> by user id (RFC 4122)
     */
    public function forUsers(array $users, \DateTimeImmutable $now): array
    {
        if ([] === $users) {
            return [];
        }
        $ids = array_map(static fn (User $user): string => $user->getId()->toBinary(), $users);
        $types = ['ids' => ArrayParameterType::BINARY];

        $facts = [];
        foreach ($ids as $id) {
            $facts[$id] = ['signupMethod' => Signups::OTHER, 'keyHint' => null, 'lastActivityAt' => null, 'trainingMs30d' => 0, 'lichess' => null];
        }

        $signups = $this->connection->fetchAllAssociative(
            "SELECT l.actor_id, JSON_UNQUOTE(JSON_EXTRACT(l.details, '$.method')) AS method, k.key_hint
               FROM early_access_invitation_log l
               JOIN early_access_invitation_key k ON k.id = l.invitation_id
              WHERE l.action = 'key_used' AND l.actor_id IN (:ids)",
            ['ids' => $ids],
            $types,
        );
        foreach ($signups as $row) {
            if (\is_string($row['actor_id']) && isset($facts[$row['actor_id']])) {
                $facts[$row['actor_id']]['signupMethod'] = Signups::method($row['method']);
                $facts[$row['actor_id']]['keyHint'] = \is_string($row['key_hint']) ? $row['key_hint'] : null;
            }
        }

        $activity = $this->connection->fetchAllAssociative(
            'SELECT user_id, MAX(occurred_at) AS last_at, SUM(CASE WHEN occurred_at >= :since THEN duration_ms ELSE 0 END) AS ms
               FROM activity_log_entry
              WHERE user_id IN (:ids)
              GROUP BY user_id',
            ['ids' => $ids, 'since' => Sql::instant($now->modify(\sprintf('-%d days', self::TRAINING_DAYS)))],
            $types,
        );
        $utc = new \DateTimeZone('UTC');
        foreach ($activity as $row) {
            if (\is_string($row['user_id']) && isset($facts[$row['user_id']])) {
                $facts[$row['user_id']]['lastActivityAt'] = \is_string($row['last_at']) ? new \DateTimeImmutable($row['last_at'], $utc) : null;
                $facts[$row['user_id']]['trainingMs30d'] = Sql::int($row['ms']);
            }
        }

        $identities = $this->connection->fetchAllAssociative(
            "SELECT user_id, metadata FROM auth_identity WHERE provider = 'lichess' AND user_id IN (:ids)",
            ['ids' => $ids],
            $types,
        );
        foreach ($identities as $row) {
            if (!\is_string($row['user_id']) || !isset($facts[$row['user_id']])) {
                continue;
            }
            $metadata = \is_string($row['metadata']) ? json_decode($row['metadata'], true) : null;
            $metadata = \is_array($metadata) ? $metadata : [];
            $picked = LichessRating::pick($metadata);
            $facts[$row['user_id']]['lichess'] = [
                'username' => \is_string($metadata['username'] ?? null) ? $metadata['username'] : null,
                'perf' => $picked['perf'] ?? null,
                'rating' => $picked['rating'] ?? null,
            ];
        }

        $byId = [];
        foreach ($users as $user) {
            $byId[$user->getId()->toRfc4122()] = $facts[$user->getId()->toBinary()];
        }

        return $byId;
    }
}
