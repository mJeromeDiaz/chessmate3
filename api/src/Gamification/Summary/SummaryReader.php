<?php

declare(strict_types=1);

namespace App\Gamification\Summary;

use App\Activity\Log\LocalDate;
use App\Entity\User;
use App\Enum\Gamification\XpKind;
use App\Enum\Training\Module;
use App\Gamification\Streak\StreakDays;
use App\Gamification\Xp\XpRules;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Psr\Clock\ClockInterface;

/**
 * The gamification summary of a user (docs/GAMIFICATION.md): XP, level and rank, the level of
 * each module, the streaks, the exercise XP of today against the daily cap.
 *
 * @phpstan-import-type Level from XpRules
 *
 * @phpstan-type ModuleLevel array{xp: int, level: int, xpInLevel: int, xpForNext: int}
 * @phpstan-type Summary array{xp: int, level: int, xpInLevel: int, xpForNext: int, rank: string, nextRank: array{rank: string, level: int}|null, modules: array<string, ModuleLevel>, streak: StreakView, today: array{date: string, exerciseXp: int, cap: int}}
 * @phpstan-type StreakView array{current: int, best: int, playedToday: bool, week: list<bool>, frozenWeek: list<bool>, protected: bool, freezeDays: int, nextMilestone: int|null}
 */
final readonly class SummaryReader
{
    public function __construct(
        private Connection $connection,
        private ClockInterface $clock,
        private StreakDays $streakDays,
    ) {
    }

    /**
     * @return Summary
     */
    public function read(User $user): array
    {
        $id = $user->getId()->toBinary();
        $today = LocalDate::of($this->clock->now(), $user->getDateTimeZone())->format('Y-m-d');

        $byModule = [];
        $total = 0;
        foreach ($this->connection->fetchAllAssociative(
            'SELECT module, SUM(xp) AS xp FROM gamification_xp_entry WHERE user_id = ? GROUP BY module',
            [$id],
            [ParameterType::BINARY],
        ) as $row) {
            $xp = is_numeric($row['xp']) ? (int) $row['xp'] : 0;
            $total += $xp;
            if (\is_string($row['module'])) {
                $byModule[$row['module']] = $xp;
            }
        }
        $modules = [];
        foreach (Module::cases() as $module) {
            $xp = $byModule[$module->value] ?? 0;
            $modules[$module->value] = ['xp' => $xp, ...XpRules::level($xp, XpRules::MODULE_STEP)];
        }

        $todayXp = $this->connection->fetchOne(
            'SELECT COALESCE(SUM(xp), 0) FROM gamification_xp_entry WHERE user_id = ? AND local_date = ? AND kind = ?',
            [$id, $today, XpKind::Exercise->value],
            [ParameterType::BINARY],
        );
        // Frozen days count as kept, the ones the freezes in stock would cover too (docs/SHOP.md).
        $streakDays = $this->streakDays->asOf($user->getId(), $today);
        $days = $streakDays['days'];

        $level = XpRules::level($total);
        $streak = Streak::of($days, $today, $streakDays['frozen']);

        return [
            'xp' => $total,
            ...$level,
            'rank' => XpRules::rank($level['level']),
            'nextRank' => XpRules::nextRank($level['level']),
            'modules' => $modules,
            'streak' => [
                ...$streak,
                'week' => Streak::week($days, $today),
                'frozenWeek' => Streak::week($streakDays['frozen'], $today),
                'protected' => $streakDays['protected'],
                'freezeDays' => $streakDays['freezeDays'],
                'nextMilestone' => Streak::nextMilestone($streak['current']),
            ],
            'today' => ['date' => $today, 'exerciseXp' => is_numeric($todayXp) ? (int) $todayXp : 0, 'cap' => XpRules::DAILY_EXERCISE_CAP],
        ];
    }
}
