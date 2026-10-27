<?php

declare(strict_types=1);

namespace App\Repository\Arena;

use App\Entity\Arena\Game;
use App\Entity\User;
use App\Enum\Arena\GameStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * The games of the arena.
 *
 * @phpstan-type Record array{won: int, drawn: int, lost: int, firstWonAt: \DateTimeImmutable|null, paid: bool}
 *
 * @extends ServiceEntityRepository<Game>
 */
class GameRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Game::class);
    }

    /**
     * Serializes the changes of a player's games until the transaction ends (two moves sent at
     * once): locks the player's row.
     */
    public function lockArenaOf(User $user): void
    {
        $this->getEntityManager()->getConnection()->executeQuery(
            'SELECT id FROM app_user WHERE id = ? FOR UPDATE',
            [$user->getId()->toBinary()],
            [ParameterType::BINARY],
        );
    }

    public function findOwned(Uuid $id, User $user): ?Game
    {
        return $this->findOneBy(['id' => $id, 'user' => $user]);
    }

    /** The player's game in progress (one at most). */
    public function findActive(User $user): ?Game
    {
        return $this->findOneBy(['user' => $user, 'status' => GameStatus::Active], ['startedAt' => 'DESC']);
    }

    /**
     * The player's results against each boss met in a finished game (cancelled games count
     * nowhere): wins, draws, losses, the first win, and whether its crystals were paid.
     *
     * @return array<string, Record> by boss
     */
    public function records(User $user): array
    {
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT boss,
                    SUM(status = ?) AS won, SUM(status = ?) AS drawn, SUM(status = ?) AS lost,
                    MIN(CASE WHEN status = ? THEN ended_at END) AS first_won_at,
                    MAX(crystals) > 0 AS paid
               FROM arena_game WHERE user_id = ? AND status IN (?, ?, ?) GROUP BY boss',
            [
                GameStatus::Won->value, GameStatus::Drawn->value, GameStatus::Lost->value, GameStatus::Won->value,
                $user->getId()->toBinary(), GameStatus::Won->value, GameStatus::Drawn->value, GameStatus::Lost->value,
            ],
            [4 => ParameterType::BINARY],
        );
        $records = [];
        foreach ($rows as $row) {
            if (!\is_string($row['boss'])) {
                continue;
            }
            $records[$row['boss']] = [
                'won' => self::int($row['won']),
                'drawn' => self::int($row['drawn']),
                'lost' => self::int($row['lost']),
                'firstWonAt' => \is_string($row['first_won_at']) ? new \DateTimeImmutable($row['first_won_at'], new \DateTimeZone('UTC')) : null,
                'paid' => 1 === self::int($row['paid']),
            ];
        }

        return $records;
    }

    /** Whether the boss's first defeat was already paid to the player. */
    public function isPaid(User $user, string $boss): bool
    {
        return false !== $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT 1 FROM arena_game WHERE user_id = ? AND boss = ? AND crystals > 0 LIMIT 1',
            [$user->getId()->toBinary(), $boss],
            [ParameterType::BINARY],
        );
    }

    /** SUM and MAX come back as numeric strings (of no row: null). */
    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
