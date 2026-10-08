<?php

declare(strict_types=1);

namespace App\Dashboard\Training;

use App\Dashboard\Period;
use App\Entity\User;
use App\Enum\Activity\ExerciseType;
use App\Enum\Training\Module;
use Doctrine\DBAL\Connection;

/**
 * Training time by local week and by module (docs/DASHBOARD.md): the durations of the exercises in
 * the activity log, on the `local_date` written with each entry. Rated and unrated puzzles are
 * the puzzles module; a week starts on Monday and every week of the period is listed, empty ones
 * included (the first and the last ones may be partial).
 *
 * ByModule: milliseconds by module (Module values), every module listed.
 *
 * @phpstan-type ByModule array<string, int>
 * @phpstan-type Week array{start: string, durationMs: ByModule}
 * @phpstan-type Total array{count: int, durationMs: int}
 */
final class TrainingTime
{
    private const MODULES = [
        ExerciseType::PuzzleRated->value => Module::Puzzles,
        ExerciseType::PuzzleUnrated->value => Module::Puzzles,
        ExerciseType::WoodpeckerPuzzle->value => Module::Woodpecker,
        ExerciseType::RepertoireSegment->value => Module::Repertoire,
        ExerciseType::FreeStudy->value => Module::Free,
        ExerciseType::CoordinatesSeries->value => Module::Coordinates,
        ExerciseType::BlindfoldPuzzle->value => Module::Blindfold,
        ExerciseType::PositionEvaluation->value => Module::Evaluation,
    ];

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return array{weeks: list<Week>, totals: array<string, Total>}
     */
    public function compute(User $user, Period $period): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT local_date, exercise_type, COUNT(*) AS n, SUM(duration_ms) AS ms
               FROM activity_log_entry
              WHERE user_id = :user AND local_date BETWEEN :from AND :today
              GROUP BY local_date, exercise_type',
            ['user' => $user->getId()->toBinary(), 'from' => $period->fromDate(), 'today' => $period->todayDate()],
        );

        $empty = array_fill_keys(array_map(static fn (Module $module): string => $module->value, Module::cases()), 0);
        /** @var array<string, ByModule> $weeks */
        $weeks = [];
        $last = self::monday($period->today);
        for ($week = self::monday($period->from); $week <= $last; $week = $week->modify('+7 days')) {
            $weeks[$week->format('Y-m-d')] = $empty;
        }
        $totals = array_map(static fn (): array => ['count' => 0, 'durationMs' => 0], $empty);

        foreach ($rows as $row) {
            $module = self::MODULES[\is_string($row['exercise_type']) ? $row['exercise_type'] : ''] ?? null;
            if (null === $module || !\is_string($row['local_date'])) {
                continue;
            }
            $ms = self::int($row['ms']);
            $start = self::monday(new \DateTimeImmutable($row['local_date']))->format('Y-m-d');
            if (isset($weeks[$start])) {
                $weeks[$start][$module->value] += $ms;
            }
            $totals[$module->value]['count'] += self::int($row['n']);
            $totals[$module->value]['durationMs'] += $ms;
        }

        $list = [];
        foreach ($weeks as $start => $durations) {
            $list[] = ['start' => $start, 'durationMs' => $durations];
        }

        return ['weeks' => $list, 'totals' => $totals];
    }

    private static function monday(\DateTimeImmutable $day): \DateTimeImmutable
    {
        return $day->setTime(0, 0)->modify(\sprintf('-%d days', (int) $day->format('N') - 1));
    }

    /** COUNT and SUM come back as numeric strings (SUM of no row: null). */
    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
