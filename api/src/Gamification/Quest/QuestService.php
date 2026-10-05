<?php

declare(strict_types=1);

namespace App\Gamification\Quest;

use App\Activity\Log\LocalDate;
use App\Dashboard\Period;
use App\Dashboard\Puzzle\ThemeStrengths;
use App\Entity\Gamification\Quest;
use App\Entity\User;
use App\Enum\Gamification\QuestTemplate;
use App\Enum\Gamification\XpKind;
use App\Enum\Training\SessionStatus;
use App\Enum\Woodpecker\SetStatus;
use App\Gamification\Xp\XpLedger;
use App\Repository\Gamification\QuestRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * The weekly quest (docs/GAMIFICATION.md), drawn and evaluated when read:
 *
 * - the quest of the local week (from Monday) is drawn on its first read, among the templates the
 *   user can do, preferring the modules played in the 4 weeks before (deterministic per user and
 *   week, not the same as last week when there is a choice); its goal is about the weekly average
 *   of those 4 weeks + 10 %, within the template's bounds;
 * - its progress counts from Monday 00:00 (local) on; once reached, it is completed at the instant
 *   of the feat and its reward gained once as XP (kind `quest`, source the quest);
 * - a past quest reached but never read again is settled on the next read.
 *
 * @phpstan-type QuestView array{id: string, template: string, theme: string|null, module: string|null, goal: int, current: int, reward: int, completed: bool, completedAt: string|null, weekStart: string, weekEnd: string}
 */
final readonly class QuestService
{
    private const HISTORY_WEEKS = 4;
    private const GROWTH = 1.1;

    public function __construct(
        private QuestRepository $quests,
        private QuestProgress $progress,
        private ThemeStrengths $themes,
        private XpLedger $ledger,
        private Connection $connection,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return QuestView
     */
    public function current(User $user): array
    {
        $now = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'));
        $today = LocalDate::of($now, $user->getDateTimeZone());
        $weekStart = $today->modify(\sprintf('-%d days', (int) $today->format('N') - 1))->setTime(0, 0);

        foreach ($this->quests->findOpenBefore($user, $weekStart) as $past) {
            $this->settle($past);
        }
        $quest = $this->quests->findOfWeek($user, $weekStart) ?? $this->draw($user, $weekStart, $now);
        $current = $this->settle($quest);
        $completedAt = $quest->getCompletedAt();

        return [
            'id' => $quest->getId()->toRfc4122(),
            'template' => $quest->getTemplate()->value,
            'theme' => $quest->getTheme(),
            'module' => $quest->getTemplate()->module()?->value,
            'goal' => $quest->getGoal(),
            'current' => $current,
            'reward' => $quest->getReward(),
            'completed' => null !== $completedAt,
            'completedAt' => $completedAt?->format(\DATE_ATOM),
            'weekStart' => $weekStart->format('Y-m-d'),
            'weekEnd' => $weekStart->modify('+6 days')->format('Y-m-d'),
        ];
    }

    /**
     * Evaluates a quest over its week; completes it (and gains its reward) once reached.
     *
     * @return int its progress
     */
    private function settle(Quest $quest): int
    {
        [$from, $to] = $this->window($quest->getUser(), $quest->getWeekStart());
        $progress = $this->progress->progress($quest->getTemplate(), $quest->getTheme(), $quest->getUser()->getId()->toBinary(), $from, $to, $quest->getGoal());
        $reachedAt = $progress['reachedAt'];
        if (null !== $reachedAt && null === $quest->getCompletedAt()) {
            $quest->complete($reachedAt);
            $this->quests->save($quest);
            $this->ledger->awardBonus(
                $quest->getUser()->getId()->toRfc4122(),
                XpKind::Quest,
                $quest->getTemplate()->module()?->value,
                $quest->getReward(),
                'gamification_quest',
                $quest->getId()->toRfc4122(),
                $reachedAt,
            );
        }

        return null === $quest->getCompletedAt() ? $progress['current'] : $quest->getGoal();
    }

    private function draw(User $user, \DateTimeImmutable $weekStart, \DateTimeImmutable $now): Quest
    {
        [$from] = $this->window($user, $weekStart);
        $historyFrom = $from->modify(\sprintf('-%d days', 7 * self::HISTORY_WEEKS));
        $id = $user->getId()->toBinary();
        $weak = $this->themes->compute($user, Period::last(30, $user, $now))['weak'][0]['key'] ?? null;

        /** @var array<string, array{QuestTemplate, string|null, int}> $candidates template => [template, theme, count in the 4 weeks] */
        $candidates = [];
        foreach (QuestTemplate::cases() as $template) {
            $theme = QuestTemplate::WeakTheme === $template ? $weak : null;
            if (!$this->eligible($template, $id, $theme)) {
                continue;
            }
            $candidates[$template->value] = [$template, $theme, $this->progress->count($template, $theme, $id, $historyFrom, $from)];
        }
        // Prefer what was played lately; training days are always possible.
        $pool = array_filter($candidates, static fn (array $c): bool => $c[2] > 0 || QuestTemplate::ActiveDays === $c[0]);
        $previous = $this->quests->findOfWeekBefore($user, $weekStart)?->getTemplate()->value;
        if (null !== $previous && \count($pool) > 1) {
            unset($pool[$previous]);
        }
        $pool = array_values($pool);
        [$template, $theme, $count] = $pool[crc32($user->getId()->toRfc4122().$weekStart->format('Y-m-d')) % \count($pool)];

        [$min, $max] = $template->bounds();
        $wanted = $count / self::HISTORY_WEEKS * self::GROWTH;
        $wanted = $template->roundsToFive() ? 5 * (int) round($wanted / 5) : (int) round($wanted);
        $goal = max($min, min($max, $wanted));

        // Two first reads at once: the unique (user, week) keeps one.
        $this->connection->executeStatement(
            'INSERT INTO gamification_quest (id, user_id, week_start, template, theme, goal, reward, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE id = id',
            [Uuid::v7()->toBinary(), $id, $weekStart->format('Y-m-d'), $template->value, $theme, $goal, $template->reward(), $now->format('Y-m-d H:i:s')],
            [ParameterType::BINARY, ParameterType::BINARY, ParameterType::STRING, ParameterType::STRING, ParameterType::STRING, ParameterType::INTEGER, ParameterType::INTEGER],
        );

        return $this->quests->findOfWeek($user, $weekStart) ?? throw new \LogicException('The quest of the week was not stored.');
    }

    private function eligible(QuestTemplate $template, string $userId, ?string $theme): bool
    {
        return match ($template) {
            QuestTemplate::RatedPuzzles, QuestTemplate::ActiveDays => true,
            QuestTemplate::WeakTheme => null !== $theme,
            QuestTemplate::Sessions => $this->exists('SELECT 1 FROM training_session WHERE user_id = ? AND status = ? LIMIT 1', $userId, SessionStatus::Completed->value),
            QuestTemplate::Woodpecker => $this->exists('SELECT 1 FROM woodpecker_set WHERE user_id = ? AND status = ? LIMIT 1', $userId, SetStatus::Active->value),
            QuestTemplate::Repertoire => $this->exists('SELECT 1 FROM repertoire WHERE user_id = ? LIMIT 1', $userId),
        };
    }

    /** Whether $sql (the user id bound first, then $params) finds a row. */
    private function exists(string $sql, string $userId, string ...$params): bool
    {
        return false !== $this->connection->fetchOne($sql, [$userId, ...array_values($params)], [ParameterType::BINARY]);
    }

    /**
     * The local week as UTC instants: Monday 00:00 to the next Monday 00:00 (daylight saving
     * included).
     *
     * @return array{\DateTimeImmutable, \DateTimeImmutable}
     */
    private function window(User $user, \DateTimeImmutable $weekStart): array
    {
        $start = new \DateTimeImmutable($weekStart->format('Y-m-d').' 00:00:00', $user->getDateTimeZone());
        $utc = new \DateTimeZone('UTC');

        return [$start->setTimezone($utc), $start->modify('+7 days')->setTimezone($utc)];
    }
}
