<?php

declare(strict_types=1);

namespace App\Gamification\Quest;

use App\Enum\Gamification\QuestTemplate;
use App\Enum\Puzzle\AttemptStatus;
use App\Enum\Repertoire\PresentationStatus;
use App\Enum\Training\SessionStatus;
use App\Gamification\Progress\Counter;

/**
 * What a quest counts between two instants (docs/GAMIFICATION.md): the progress of the week, and
 * the activity of the weeks before it (its goal).
 */
final readonly class QuestProgress
{
    public function __construct(private Counter $counter)
    {
    }

    /**
     * @return array{current: int, reachedAt: \DateTimeImmutable|null}
     */
    public function progress(QuestTemplate $template, ?string $theme, string $userId, \DateTimeImmutable $from, \DateTimeImmutable $to, int $goal): array
    {
        [$sql, $params] = $this->query($template, $theme, $from, $to);

        return $this->counter->nth($sql, $userId, $goal, $params);
    }

    public function count(QuestTemplate $template, ?string $theme, string $userId, \DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        [$sql, $params] = $this->query($template, $theme, $from, $to);

        return $this->counter->count($sql, $userId, $params);
    }

    /**
     * One instant `at` per element counted.
     *
     * @return array{string, array<string, string>}
     */
    private function query(QuestTemplate $template, ?string $theme, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $params = [
            'from' => $from->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            'to' => $to->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
        ];
        $solved = AttemptStatus::Solved->value;

        return match ($template) {
            QuestTemplate::RatedPuzzles => [
                'SELECT submitted_at AS at FROM puzzle_attempt
                  WHERE user_id = :user AND rated = 1 AND status = :status AND submitted_at >= :from AND submitted_at < :to',
                [...$params, 'status' => $solved],
            ],
            QuestTemplate::WeakTheme => [
                'SELECT a.submitted_at AS at FROM puzzle_attempt a JOIN puzzle p ON p.id = a.puzzle_id
                  WHERE a.user_id = :user AND a.rated = 1 AND a.status = :status AND a.mistakes = 0 AND a.hint_level = 0
                    AND a.solution_shown = 0 AND JSON_CONTAINS(p.themes, :theme) AND a.submitted_at >= :from AND a.submitted_at < :to',
                [...$params, 'status' => $solved, 'theme' => json_encode((string) $theme, \JSON_THROW_ON_ERROR)],
            ],
            QuestTemplate::Sessions => [
                'SELECT closed_at AS at FROM training_session
                  WHERE user_id = :user AND status = :status AND closed_at >= :from AND closed_at < :to',
                [...$params, 'status' => SessionStatus::Completed->value],
            ],
            QuestTemplate::Woodpecker => [
                'SELECT a.submitted_at AS at FROM woodpecker_attempt a JOIN woodpecker_cycle c ON c.id = a.cycle_id
                   JOIN woodpecker_set s ON s.id = c.set_id
                  WHERE s.user_id = :user AND a.status <> :status AND a.submitted_at >= :from AND a.submitted_at < :to',
                [...$params, 'status' => AttemptStatus::Pending->value],
            ],
            QuestTemplate::Repertoire => [
                'SELECT finished_at AS at FROM repertoire_presentation
                  WHERE user_id = :user AND status = :status AND finished_at >= :from AND finished_at < :to',
                [...$params, 'status' => PresentationStatus::Succeeded->value],
            ],
            // One element per active local day: its first exercise.
            QuestTemplate::ActiveDays => [
                'SELECT MIN(occurred_at) AS at FROM activity_log_entry
                  WHERE user_id = :user AND occurred_at >= :from AND occurred_at < :to GROUP BY local_date',
                $params,
            ],
        };
    }
}
