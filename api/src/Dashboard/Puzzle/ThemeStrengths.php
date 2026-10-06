<?php

declare(strict_types=1);

namespace App\Dashboard\Puzzle;

use App\Dashboard\Period;
use App\Entity\User;
use App\Enum\Puzzle\AttemptStatus;
use App\Enum\Puzzle\ThemeCategory;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Strong and weak puzzle themes (docs/DASHBOARD.md), over the rated puzzles attempted in the period
 * (Woodpecker repeats its puzzles and would weigh them several times). A success is a puzzle solved
 * without help: no mistake, hint or solution, as for the rating. A theme counts from
 * {@see MIN_ATTEMPTS} attempts; lengths, goals and origins (short, advantage, master...) are not
 * themes to work on and are left out. Their keys come from the catalogue's database, the attempts
 * (with a copy of their puzzle's themes) from the main one (docs/DEPLOY_OVH.md, § 3).
 *
 * @phpstan-type ThemeRow array{key: string, attempts: int, successCount: int, successRate: float}
 * @phpstan-type Themes array{attempts: int, successCount: int, minAttempts: int, themes: list<ThemeRow>, strong: list<ThemeRow>, weak: list<ThemeRow>}
 */
final class ThemeStrengths
{
    public const MIN_ATTEMPTS = 5;
    /** Strong and weak themes listed, at most. */
    public const LIMIT = 5;

    private const LEFT_OUT = [ThemeCategory::Lengths, ThemeCategory::Goals, ThemeCategory::Origin];

    public function __construct(
        private readonly Connection $connection,
        #[Autowire(service: 'doctrine.dbal.catalog_connection')]
        private readonly Connection $catalog,
    ) {
    }

    /**
     * @return Themes
     */
    public function compute(User $user, Period $period): array
    {
        $params = [
            'user' => $user->getId()->toBinary(),
            'since' => $period->since->format('Y-m-d H:i:s'),
            'statuses' => [AttemptStatus::Solved->value, AttemptStatus::Failed->value],
            'solved' => AttemptStatus::Solved->value,
        ];
        $types = ['statuses' => ArrayParameterType::STRING];
        $clean = 'a.status = :solved AND a.mistakes = 0 AND a.hint_level = 0 AND a.solution_shown = 0';
        $where = 'a.user_id = :user AND a.rated = 1 AND a.status IN (:statuses) AND a.started_at >= :since';

        $total = $this->connection->fetchAssociative(
            "SELECT COUNT(*) AS n, SUM($clean) AS ok FROM puzzle_attempt a WHERE $where",
            $params,
            $types,
        ) ?: [];
        $rows = $this->connection->fetchAllAssociative(
            "SELECT jt.theme_key, COUNT(*) AS n, SUM($clean) AS ok
               FROM puzzle_attempt a
               JOIN JSON_TABLE(a.puzzle_themes, '$[*]' COLUMNS (theme_key VARCHAR(32) PATH '$')) jt
              WHERE $where
              GROUP BY jt.theme_key
             HAVING n >= :min",
            [...$params, 'min' => self::MIN_ATTEMPTS],
            [...$types, 'min' => ParameterType::INTEGER],
        );
        $leftOut = array_flip(array_filter($this->catalog->fetchFirstColumn(
            'SELECT theme_key FROM puzzle_theme WHERE category IN (:categories)',
            ['categories' => array_map(static fn (ThemeCategory $category): string => $category->value, self::LEFT_OUT)],
            ['categories' => ArrayParameterType::STRING],
        ), 'is_string'));

        $themes = [];
        foreach ($rows as $row) {
            $key = \is_string($row['theme_key']) ? $row['theme_key'] : '';
            if ('' === $key || isset($leftOut[$key])) {
                continue;
            }
            $attempts = self::int($row['n']);
            $successCount = self::int($row['ok']);
            $themes[] = ['key' => $key, 'attempts' => $attempts, 'successCount' => $successCount, 'successRate' => round($successCount / $attempts, 4)];
        }

        return [
            'attempts' => self::int($total['n'] ?? null),
            'successCount' => self::int($total['ok'] ?? null),
            'minAttempts' => self::MIN_ATTEMPTS,
            ...self::split($themes),
        ];
    }

    /**
     * Sorts the themes from the best to the worst and splits them: the strong ones from the top,
     * the weak ones from the bottom (the worst first), never the same theme in both.
     *
     * @param list<ThemeRow> $themes
     *
     * @return array{themes: list<ThemeRow>, strong: list<ThemeRow>, weak: list<ThemeRow>}
     */
    public static function split(array $themes): array
    {
        usort($themes, static fn (array $a, array $b): int => [$b['successRate'], $b['attempts'], $a['key']] <=> [$a['successRate'], $a['attempts'], $b['key']]);
        $count = \count($themes);
        $strong = \array_slice($themes, 0, min(self::LIMIT, intdiv($count + 1, 2)));
        $weakCount = min(self::LIMIT, intdiv($count, 2));
        $weak = 0 === $weakCount ? [] : \array_slice($themes, -$weakCount);
        // The weakest first: among equal rates, the most attempted.
        usort($weak, static fn (array $a, array $b): int => [$a['successRate'], $b['attempts'], $a['key']] <=> [$b['successRate'], $a['attempts'], $b['key']]);

        return ['themes' => $themes, 'strong' => $strong, 'weak' => $weak];
    }

    /** COUNT and SUM come back as numeric strings (SUM of no row: null). */
    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
