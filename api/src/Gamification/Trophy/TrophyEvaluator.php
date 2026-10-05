<?php

declare(strict_types=1);

namespace App\Gamification\Trophy;

use App\Entity\User;
use App\Enum\Gamification\Trophy;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Uid\Uuid;

/**
 * The trophies of a user (docs/GAMIFICATION.md), evaluated when they are read: a trophy now reached
 * is stored once, at the date of the feat; one already won is not computed again. The rebuild
 * forgets them and evaluates them all again.
 *
 * @phpstan-type TrophyView array{key: string, goal: int, current: int, unlocked: bool, unlockedAt: string|null, ratio: float|null}
 */
final readonly class TrophyEvaluator
{
    public function __construct(
        private Connection $connection,
        private TrophyProgress $progress,
    ) {
    }

    /**
     * Every trophy, in the catalogue's order.
     *
     * @return list<TrophyView>
     */
    public function trophies(User $user): array
    {
        $id = $user->getId()->toBinary();
        /** @var array<string, string> $won trophy => unlocked at (UTC) */
        $won = [];
        foreach ($this->connection->fetchAllAssociative('SELECT trophy, unlocked_at FROM gamification_trophy WHERE user_id = ?', [$id], [ParameterType::BINARY]) as $row) {
            if (\is_string($row['trophy']) && \is_string($row['unlocked_at'])) {
                $won[$row['trophy']] = $row['unlocked_at'];
            }
        }

        $views = [];
        foreach (Trophy::cases() as $trophy) {
            $goal = $trophy->goal();
            if (isset($won[$trophy->value])) {
                $views[] = self::view($trophy, $goal, new \DateTimeImmutable($won[$trophy->value], new \DateTimeZone('UTC')), null);
                continue;
            }
            $progress = $this->progress->of($user, $trophy);
            $reachedAt = $progress['reachedAt'];
            if (null !== $reachedAt) {
                $this->connection->executeStatement(
                    'INSERT INTO gamification_trophy (id, user_id, trophy, unlocked_at) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE id = id',
                    [Uuid::v7()->toBinary(), $id, $trophy->value, $reachedAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s')],
                    [ParameterType::BINARY, ParameterType::BINARY],
                );
            }
            $views[] = null === $reachedAt
                ? ['key' => $trophy->value, 'goal' => $goal, 'current' => $progress['current'], 'unlocked' => false, 'unlockedAt' => null, 'ratio' => $progress['ratio'] ?? null]
                : self::view($trophy, $goal, $reachedAt, $progress['ratio'] ?? null);
        }

        return $views;
    }

    /**
     * Forgets the trophies of $user and evaluates them again (after a change of the rules).
     *
     * @return int trophies won
     */
    public function rebuild(User $user): int
    {
        $this->connection->executeStatement('DELETE FROM gamification_trophy WHERE user_id = ?', [$user->getId()->toBinary()], [ParameterType::BINARY]);

        return \count(array_filter($this->trophies($user), static fn (array $view): bool => $view['unlocked']));
    }

    /**
     * @return TrophyView
     */
    private static function view(Trophy $trophy, int $goal, \DateTimeImmutable $at, ?float $ratio): array
    {
        return ['key' => $trophy->value, 'goal' => $goal, 'current' => $goal, 'unlocked' => true, 'unlockedAt' => $at->format(\DATE_ATOM), 'ratio' => $ratio];
    }
}
