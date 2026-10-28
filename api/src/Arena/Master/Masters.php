<?php

declare(strict_types=1);

namespace App\Arena\Master;

use App\Arena\ArenaRules;
use App\Entity\User;
use App\Enum\Blindfold\AttemptStatus;
use App\Enum\MindMap\Capacity;
use App\Enum\MindMap\StepKind;
use App\Enum\PuzzleStreak\AttemptStatus as StreakAttemptStatus;
use App\Enum\Training\CloseReason;
use App\Enum\Training\Module;
use App\Enum\Training\RunStatus;
use App\MindMap\Path\Paths;
use App\Repository\Arena\UnlockRepository;
use App\Repository\MindMap\ProgressRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Psr\Clock\ClockInterface;

/**
 * Where the player stands on each professor's feat (docs/ARENA.md, § 2 bis): Lizy, every lesson of
 * the endgames path mastered; Albert, a puzzle streak of 50; Aaron, a repertoire of more than 50
 * lines; Ray, a hard free blindfold run validated. Reached at the instant of the feat; a professor
 * already arrived stays (Aaron's lines may be deleted since: his arrival is his date).
 *
 * @phpstan-type MasterProgress array{current: int, goal: int, reachedAt: \DateTimeImmutable|null}
 */
final readonly class Masters
{
    public function __construct(
        private Connection $connection,
        private ProgressRepository $progress,
        private UnlockRepository $unlocks,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return array<string, MasterProgress> by professor, in {@see ArenaRules::MASTERS}' order
     */
    public function of(User $user): array
    {
        $unlocks = $this->unlocks->byBoss($user);
        $result = [];
        foreach (ArenaRules::MASTERS as $master) {
            $result[$master] = $this->feat($user, $master, ($unlocks[$master] ?? null)?->getUnlockedAt());
        }

        return $result;
    }

    /**
     * @return MasterProgress
     */
    public function one(User $user, string $master): array
    {
        return $this->feat($user, $master, ($this->unlocks->byBoss($user)[$master] ?? null)?->getUnlockedAt());
    }

    /**
     * @param \DateTimeImmutable|null $arrivedAt when the professor arrived in the arena, if they did
     *
     * @return MasterProgress
     */
    private function feat(User $user, string $master, ?\DateTimeImmutable $arrivedAt): array
    {
        $id = $user->getId()->toBinary();
        $progress = match ($master) {
            'lizy' => $this->endgames($user),
            'albert' => $this->puzzleStreak($id),
            'aaron' => $this->repertoireLines($id, $arrivedAt),
            'ray' => $this->blindfoldRun($id),
            default => throw new \LogicException(\sprintf('No feat for "%s".', $master)),
        };
        $progress['reachedAt'] ??= $arrivedAt;

        return $progress;
    }

    /**
     * Lessons of the endgames path mastered (its mastery chapters' exercises completed), reached
     * with the last one.
     *
     * @return MasterProgress
     */
    private function endgames(User $user): array
    {
        $rows = $this->progress->byStep($user, Capacity::Endgames);
        $goal = 0;
        $current = 0;
        $last = null;
        foreach (Paths::chapters(Capacity::Endgames) as $chapter) {
            if (!$chapter->mastery) {
                continue;
            }
            foreach ($chapter->allSteps() as $step) {
                if (StepKind::Exercise !== $step->kind) {
                    continue;
                }
                ++$goal;
                $at = ($rows[$step->key] ?? null)?->getCompletedAt();
                if (null !== $at) {
                    ++$current;
                    $last = null === $last || $at > $last ? $at : $last;
                }
            }
        }

        return ['current' => $current, 'goal' => $goal, 'reachedAt' => $goal > 0 && $current === $goal ? $last : null];
    }

    /**
     * The longest puzzle streak, reached when the 50th puzzle of a streak was solved.
     *
     * @return MasterProgress
     */
    private function puzzleStreak(string $userId): array
    {
        $goal = ArenaRules::ALBERT_STREAK;
        $best = $this->connection->fetchOne('SELECT COALESCE(MAX(length), 0) FROM puzzle_streak WHERE user_id = ?', [$userId], [ParameterType::BINARY]);
        $best = is_numeric($best) ? (int) $best : 0;
        $at = $best >= $goal ? $this->connection->fetchOne(
            'SELECT MIN(submitted_at) FROM puzzle_streak_attempt WHERE user_id = ? AND position = ? AND status = ?',
            [$userId, $goal - 1, StreakAttemptStatus::Solved->value],
            [ParameterType::BINARY, ParameterType::INTEGER, ParameterType::STRING],
        ) : null;

        return ['current' => min($best, $goal), 'goal' => $goal, 'reachedAt' => self::instant($at)];
    }

    /**
     * The lines of the player's biggest repertoire, as its Lines tab shows them (docs/REPERTOIRE.md):
     * a canonical move into a position without continuation, or a transposition. Its date is not
     * kept by the graph: his arrival in the arena, else now.
     *
     * @return MasterProgress
     */
    private function repertoireLines(string $userId, ?\DateTimeImmutable $arrivedAt): array
    {
        $goal = ArenaRules::AARON_LINES;
        $best = $this->connection->fetchOne(
            'SELECT COALESCE(MAX(n), 0) FROM (
                SELECT COUNT(*) AS n FROM repertoire_move m JOIN repertoire r ON r.id = m.repertoire_id
                 WHERE r.user_id = ?
                   AND (m.canonical = 0 OR NOT EXISTS (SELECT 1 FROM repertoire_move c WHERE c.from_position_id = m.to_position_id))
                 GROUP BY m.repertoire_id
             ) t',
            [$userId],
            [ParameterType::BINARY],
        );
        $best = is_numeric($best) ? (int) $best : 0;

        return ['current' => min($best, $goal), 'goal' => $goal, 'reachedAt' => $arrivedAt ?? ($best >= $goal ? $this->clock->now() : null)];
    }

    /**
     * The first free blindfold run (no session, no path) played to its end with Ray's settings and
     * enough puzzles solved without a peek.
     *
     * @return MasterProgress
     */
    private function blindfoldRun(string $userId): array
    {
        $at = $this->connection->fetchOne(
            "SELECT r.closed_at FROM training_run r JOIN blindfold_puzzle_attempt a ON a.run_id = r.id
              WHERE r.user_id = :user AND r.module = :module AND r.parent_id IS NULL AND r.path_step IS NULL
                AND r.status = :closed AND r.close_reason = :timeUp AND r.budget_seconds >= :budget
                AND JSON_UNQUOTE(JSON_EXTRACT(r.config, '$.level')) = :level
                AND CAST(JSON_EXTRACT(r.config, '$.length') AS UNSIGNED) >= :length
                AND CAST(JSON_EXTRACT(r.config, '$.visibleSeconds') AS UNSIGNED) <= :visible
                AND a.status <> :pending
              GROUP BY r.id, r.closed_at
             HAVING COUNT(*) >= :played AND SUM(a.status = :solved) * 100 >= COUNT(*) * :percent
              ORDER BY r.closed_at LIMIT 1",
            [
                'user' => $userId,
                'module' => Module::Blindfold->value,
                'closed' => RunStatus::Closed->value,
                'timeUp' => CloseReason::TimeUp->value,
                'budget' => ArenaRules::RAY_MIN_SECONDS,
                'level' => ArenaRules::RAY_LEVEL,
                'length' => ArenaRules::RAY_MIN_LENGTH,
                'visible' => ArenaRules::RAY_MAX_VISIBLE_SECONDS,
                'pending' => AttemptStatus::Pending->value,
                'played' => ArenaRules::RAY_MIN_PUZZLES,
                'solved' => AttemptStatus::Solved->value,
                'percent' => ArenaRules::RAY_MIN_PERCENT,
            ],
            [
                'user' => ParameterType::BINARY,
                'budget' => ParameterType::INTEGER,
                'length' => ParameterType::INTEGER,
                'visible' => ParameterType::INTEGER,
                'played' => ParameterType::INTEGER,
                'percent' => ParameterType::INTEGER,
            ],
        );
        $reachedAt = self::instant($at);

        return ['current' => null === $reachedAt ? 0 : 1, 'goal' => 1, 'reachedAt' => $reachedAt];
    }

    private static function instant(mixed $value): ?\DateTimeImmutable
    {
        return \is_string($value) ? new \DateTimeImmutable($value, new \DateTimeZone('UTC')) : null;
    }
}
