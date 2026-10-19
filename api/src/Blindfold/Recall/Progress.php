<?php

declare(strict_types=1);

namespace App\Blindfold\Recall;

use App\Entity\User;
use App\Repository\Blindfold\RecallAttemptRepository;

/**
 * Where a user stands on the twenty recall levels (docs/BLINDFOLD_RECALL.md): level 1 is open from
 * the start, each next one after RecallRules::UNLOCK_EXACT exact rebuilds at the one before. Read
 * from the rebuilds themselves, nothing stored aside.
 *
 * @phpstan-import-type Counts from RecallAttemptRepository
 *
 * @phpstan-type LevelProgress array{level: int, stage: int, played: int, exact: int, close: int, missed: int, unlocked: bool}
 */
final readonly class Progress
{
    public function __construct(
        private RecallAttemptRepository $attempts,
    ) {
    }

    /**
     * @return list<LevelProgress> every level, first to last
     */
    public function levels(User $user): array
    {
        $counts = $this->attempts->countsByLevel($user);
        $levels = [];
        foreach (array_keys(RecallRules::LEVELS) as $level) {
            $levels[] = [
                'level' => $level,
                'stage' => RecallRules::stage($level),
                ...($counts[$level] ?? ['played' => 0, 'exact' => 0, 'close' => 0, 'missed' => 0]),
                'unlocked' => self::opens($level, $counts),
            ];
        }

        return $levels;
    }

    /**
     * The highest level open to the user.
     */
    public function highest(User $user): int
    {
        $counts = $this->attempts->countsByLevel($user);
        $highest = 1;
        foreach (array_keys(RecallRules::LEVELS) as $level) {
            if (self::opens($level, $counts)) {
                $highest = max($highest, $level);
            }
        }

        return $highest;
    }

    public function isUnlocked(User $user, int $level): bool
    {
        return RecallRules::exists($level) && (1 === $level || $this->attempts->countExact($user, $level - 1) >= RecallRules::UNLOCK_EXACT);
    }

    /**
     * @param array<int, Counts> $counts
     */
    private static function opens(int $level, array $counts): bool
    {
        return 1 === $level || ($counts[$level - 1]['exact'] ?? 0) >= RecallRules::UNLOCK_EXACT;
    }
}
