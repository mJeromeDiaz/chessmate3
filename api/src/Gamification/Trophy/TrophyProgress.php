<?php

declare(strict_types=1);

namespace App\Gamification\Trophy;

use App\Entity\User;
use App\Enum\Gamification\Trophy;
use App\Enum\Puzzle\AttemptStatus;
use App\Enum\Repertoire\PresentationStatus;
use App\Enum\Training\SessionStatus;
use App\Enum\Woodpecker\CycleStatus;
use App\Enum\Woodpecker\SetStatus;
use App\Gamification\Progress\Counter;
use App\Gamification\Summary\Streak;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Psr\Clock\ClockInterface;

/**
 * Where a user stands on each trophy (docs/GAMIFICATION.md): the progress towards its goal and,
 * when reached, the instant of the feat (the 100th fork, the day the streak reached 7…), read from
 * what was played.
 *
 * @phpstan-type Progress array{current: int, reachedAt: \DateTimeImmutable|null, ratio?: float|null}
 */
final readonly class TrophyProgress
{
    /** Iron memory: the window of tests, and the success it needs. */
    public const IRON_MEMORY_DAYS = 30;
    public const IRON_MEMORY_RATE = 0.95;

    private const MARATHON_MS = 3_600_000;

    public function __construct(
        private Connection $connection,
        private Counter $counter,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return Progress
     */
    public function of(User $user, Trophy $trophy): array
    {
        $id = $user->getId()->toBinary();

        return match ($trophy) {
            Trophy::OnFire, Trophy::Unstoppable => $this->streak($id, $trophy->goal()),
            Trophy::FirstStep => $this->counter->nth('SELECT occurred_at AS at FROM activity_log_entry WHERE user_id = :user', $id, 1),
            Trophy::Woodpecker => $this->counter->nth(
                'SELECT c.completed_at AS at FROM woodpecker_cycle c JOIN woodpecker_set s ON s.id = c.set_id
                  WHERE s.user_id = :user AND c.status = :status',
                $id,
                1,
                ['status' => CycleStatus::Completed->value],
            ),
            Trophy::SteelWoodpecker => $this->counter->nth(
                'SELECT completed_at AS at FROM woodpecker_set WHERE user_id = :user AND status = :status',
                $id,
                1,
                ['status' => SetStatus::Completed->value],
            ),
            Trophy::Conductor => $this->counter->nth(
                'SELECT closed_at AS at FROM training_session WHERE user_id = :user AND status = :status',
                $id,
                $trophy->goal(),
                ['status' => SessionStatus::Completed->value],
            ),
            Trophy::GoldenFork => $this->counter->nth(
                "SELECT a.submitted_at AS at FROM puzzle_attempt a JOIN puzzle p ON p.id = a.puzzle_id
                  WHERE a.user_id = :user AND a.rated = 1 AND a.status = :status AND a.mistakes = 0 AND a.hint_level = 0
                    AND a.solution_shown = 0 AND JSON_CONTAINS(p.themes, '\"fork\"')",
                $id,
                $trophy->goal(),
                ['status' => AttemptStatus::Solved->value],
            ),
            Trophy::Centurion => $this->counter->nth(
                'SELECT submitted_at AS at FROM puzzle_attempt WHERE user_id = :user AND status = :status
                 UNION ALL
                 SELECT a.submitted_at FROM woodpecker_attempt a JOIN woodpecker_cycle c ON c.id = a.cycle_id
                   JOIN woodpecker_set s ON s.id = c.set_id
                  WHERE s.user_id = :user AND a.status = :status',
                $id,
                $trophy->goal(),
                ['status' => AttemptStatus::Solved->value],
            ),
            Trophy::Marathon => $this->marathon($id, $trophy->goal()),
            Trophy::IronMemory => $this->ironMemory($id, $trophy->goal()),
        };
    }

    /**
     * @return Progress
     */
    private function streak(string $userId, int $goal): array
    {
        $days = array_values(array_filter($this->connection->fetchFirstColumn(
            'SELECT DISTINCT local_date FROM activity_log_entry WHERE user_id = ? ORDER BY local_date',
            [$userId],
            [ParameterType::BINARY],
        ), 'is_string'));
        $best = Streak::of($days, '0000-00-00')['best'];
        $day = Streak::reachedOn($days, $goal);
        // The streak reached its length with the first exercise of that day.
        $at = null === $day ? null : $this->connection->fetchOne(
            'SELECT MIN(occurred_at) FROM activity_log_entry WHERE user_id = ? AND local_date = ?',
            [$userId, $day],
            [ParameterType::BINARY],
        );

        return ['current' => min($best, $goal), 'reachedAt' => Counter::instant($at)];
    }

    /**
     * Hours of training, and the exercise that passed the goal.
     *
     * @return Progress
     */
    private function marathon(string $userId, int $goalHours): array
    {
        $total = $this->connection->fetchOne('SELECT COALESCE(SUM(duration_ms), 0) FROM activity_log_entry WHERE user_id = ?', [$userId], [ParameterType::BINARY]);
        $hours = intdiv(is_numeric($total) ? (int) $total : 0, self::MARATHON_MS);
        $at = $hours >= $goalHours ? $this->connection->fetchOne(
            'SELECT t.occurred_at FROM (
                SELECT occurred_at, SUM(duration_ms) OVER (ORDER BY occurred_at, id) AS total FROM activity_log_entry WHERE user_id = ?
             ) t WHERE t.total >= ? ORDER BY t.occurred_at LIMIT 1',
            [$userId, $goalHours * self::MARATHON_MS],
            [ParameterType::BINARY, ParameterType::INTEGER],
        ) : null;

        return ['current' => min($hours, $goalHours), 'reachedAt' => Counter::instant($at)];
    }

    /**
     * Repertoire tests (first presentations of a segment in a run) over a sliding window of 30
     * days: reached at the first test after which the window holds at least $goal tests, 95 % of
     * them succeeded. The progress is the window ending now (tests and their success rate).
     *
     * @return Progress
     */
    private function ironMemory(string $userId, int $goal): array
    {
        $tests = $this->connection->fetchAllAssociative(
            'SELECT finished_at, status FROM repertoire_presentation
              WHERE user_id = ? AND presentation_rank = 1 AND status IN (?) AND finished_at IS NOT NULL ORDER BY finished_at',
            [$userId, [PresentationStatus::Succeeded->value, PresentationStatus::Failed->value]],
            [ParameterType::BINARY, ArrayParameterType::STRING],
        );
        $window = [];
        $succeeded = 0;
        $reachedAt = null;
        foreach ($tests as $test) {
            $at = Counter::instant($test['finished_at']);
            if (null === $at) {
                continue;
            }
            $ok = PresentationStatus::Succeeded->value === $test['status'];
            $window[] = [$at, $ok];
            $succeeded += (int) $ok;
            $from = $at->modify(\sprintf('-%d days', self::IRON_MEMORY_DAYS));
            while ($window[0][0] <= $from) {
                $succeeded -= (int) array_shift($window)[1];
            }
            if (null === $reachedAt && \count($window) >= $goal && $succeeded >= self::IRON_MEMORY_RATE * \count($window)) {
                $reachedAt = $at;
            }
        }
        // The window ending now.
        $from = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->modify(\sprintf('-%d days', self::IRON_MEMORY_DAYS));
        $recent = array_values(array_filter($window, static fn (array $test): bool => $test[0] > $from));
        $recentOk = \count(array_filter($recent, static fn (array $test): bool => $test[1]));

        return [
            'current' => min(\count($recent), $goal),
            'reachedAt' => $reachedAt,
            'ratio' => [] === $recent ? null : round($recentOk / \count($recent), 4),
        ];
    }
}
