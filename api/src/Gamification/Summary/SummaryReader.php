<?php

declare(strict_types=1);

namespace App\Gamification\Summary;

use App\Activity\Log\LocalDate;
use App\Entity\User;
use App\Enum\Gamification\XpKind;
use App\Enum\Training\Module;
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
 * @phpstan-type Summary array{xp: int, level: int, xpInLevel: int, xpForNext: int, rank: string, nextRank: array{rank: string, level: int}|null, modules: array<string, ModuleLevel>, streak: array{current: int, best: int, playedToday: bool}, today: array{exerciseXp: int, cap: int}}
 */
final readonly class SummaryReader
{
    public function __construct(
        private Connection $connection,
        private ClockInterface $clock,
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
        $days = array_values(array_filter($this->connection->fetchFirstColumn(
            'SELECT DISTINCT local_date FROM activity_log_entry WHERE user_id = ? ORDER BY local_date',
            [$id],
            [ParameterType::BINARY],
        ), 'is_string'));

        $level = XpRules::level($total);

        return [
            'xp' => $total,
            ...$level,
            'rank' => XpRules::rank($level['level']),
            'nextRank' => XpRules::nextRank($level['level']),
            'modules' => $modules,
            'streak' => Streak::of($days, $today),
            'today' => ['exerciseXp' => is_numeric($todayXp) ? (int) $todayXp : 0, 'cap' => XpRules::DAILY_EXERCISE_CAP],
        ];
    }
}
