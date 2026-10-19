<?php

declare(strict_types=1);

namespace App\PuzzleRush\Record;

use App\Entity\Training\Run;
use App\Entity\User;
use App\Enum\Training\Module;
use App\Enum\Training\RunStatus;
use App\PuzzleRush\RushRules;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Uid\Uuid;

/**
 * The player's rush records and history (docs/PUZZLE_RUSH.md), read from the runs' frozen
 * summaries: a record is the best score of a length, among the ranked runs only (played until the
 * time was up, without themes: `metrics.ranked`). A handful of runs per player: no table of its own.
 *
 * @phpstan-type Record array{budgetSeconds: int, best: int|null, runs: int, at: string|null}
 * @phpstan-type Recent array{id: string, budgetSeconds: int, score: int, solved: int, failed: int, accuracy: float|null, puzzlesPerMinute: float|null, ranked: bool, themes: list<string>, closedAt: string}
 */
final readonly class Records
{
    public const RECENT = 10;

    public function __construct(private Connection $connection)
    {
    }

    /**
     * The best ranked score of the run's length, other runs only; null when there is none.
     */
    public function bestOutside(Run $run): ?int
    {
        $best = $this->connection->fetchOne(
            "SELECT MAX(CAST(summary->>'$.metrics.score' AS UNSIGNED)) FROM training_run
              WHERE user_id = ? AND module = ? AND status = ? AND budget_seconds = ? AND id <> ? AND summary->>'$.metrics.ranked' = 'true'",
            [$run->getUser()->getId()->toBinary(), Module::PuzzleRush->value, RunStatus::Closed->value, $run->getBudgetSeconds(), $run->getId()->toBinary()],
            [ParameterType::BINARY, ParameterType::STRING, ParameterType::STRING, ParameterType::INTEGER, ParameterType::BINARY],
        );

        return is_numeric($best) ? (int) $best : null;
    }

    /**
     * The record of each length, every length listed (best null: never played ranked), and when it
     * was first reached.
     *
     * @return list<Record>
     */
    public function of(User $user): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT budget_seconds, CAST(summary->>'$.metrics.score' AS UNSIGNED) AS score, closed_at FROM training_run
              WHERE user_id = ? AND module = ? AND status = ? AND summary->>'$.metrics.ranked' = 'true'
              ORDER BY closed_at, id",
            [$user->getId()->toBinary(), Module::PuzzleRush->value, RunStatus::Closed->value],
            [ParameterType::BINARY, ParameterType::STRING, ParameterType::STRING],
        );
        $records = [];
        foreach (RushRules::BUDGETS as $budget) {
            $records[$budget] = ['budgetSeconds' => $budget, 'best' => null, 'runs' => 0, 'at' => null];
        }
        foreach ($rows as $row) {
            $budget = self::int($row['budget_seconds']);
            if (!isset($records[$budget])) {
                continue;
            }
            $score = self::int($row['score']);
            ++$records[$budget]['runs'];
            // Oldest first: the first run to reach the best keeps its date.
            if (null === $records[$budget]['best'] || $score > $records[$budget]['best']) {
                $records[$budget]['best'] = $score;
                $records[$budget]['at'] = self::date($row['closed_at']);
            }
        }

        return array_values($records);
    }

    /**
     * The player's last rushes, the latest first.
     *
     * @return list<Recent>
     */
    public function recent(User $user, int $limit = self::RECENT): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, budget_seconds, summary, closed_at FROM training_run
              WHERE user_id = ? AND module = ? AND status = ? AND summary IS NOT NULL
              ORDER BY started_at DESC, id DESC LIMIT ?',
            [$user->getId()->toBinary(), Module::PuzzleRush->value, RunStatus::Closed->value, $limit],
            [ParameterType::BINARY, ParameterType::STRING, ParameterType::STRING, ParameterType::INTEGER],
        );
        $recent = [];
        foreach ($rows as $row) {
            $summary = \is_string($row['summary']) ? json_decode($row['summary'], true) : null;
            $metrics = \is_array($summary) && \is_array($summary['metrics'] ?? null) ? $summary['metrics'] : [];
            $themes = \is_array($metrics['themes'] ?? null) ? array_values(array_filter($metrics['themes'], 'is_string')) : [];
            $recent[] = [
                'id' => Uuid::fromBinary(\is_string($row['id']) ? $row['id'] : '')->toRfc4122(),
                'budgetSeconds' => self::int($row['budget_seconds']),
                'score' => self::int($metrics['score'] ?? 0),
                'solved' => self::int($metrics['solved'] ?? 0),
                'failed' => self::int($metrics['failed'] ?? 0),
                'accuracy' => is_numeric($metrics['accuracy'] ?? null) ? (float) $metrics['accuracy'] : null,
                'puzzlesPerMinute' => is_numeric($metrics['puzzlesPerMinute'] ?? null) ? (float) $metrics['puzzlesPerMinute'] : null,
                'ranked' => true === ($metrics['ranked'] ?? null),
                'themes' => $themes,
                'closedAt' => self::date($row['closed_at']) ?? '',
            ];
        }

        return $recent;
    }

    private static function date(mixed $value): ?string
    {
        return \is_string($value) ? (new \DateTimeImmutable($value, new \DateTimeZone('UTC')))->format(\DATE_ATOM) : null;
    }

    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
