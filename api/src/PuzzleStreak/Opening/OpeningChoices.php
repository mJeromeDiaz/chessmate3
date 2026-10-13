<?php

declare(strict_types=1);

namespace App\PuzzleStreak\Opening;

use App\Entity\User;
use App\Puzzle\Opening\OpeningFamily;
use App\PuzzleStreak\Selection\Topic;
use App\PuzzleStreak\StreakRules;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The openings a puzzle streak can draw from (docs/PUZZLE_STREAK.md): every family of the
 * catalogue with enough puzzles for a side, and the user's own first: the families of their
 * repertoires (named positions, with the repertoire's colour) and of their Lichess games already
 * read (the tailored session's opening sets, the build of a repertoire from their games still
 * kept). Reads the catalogue's database apart from the main one (docs/DEPLOY_OVH.md, § 3).
 *
 * @phpstan-type Family array{tag: string, name: string, white: int, black: int}
 * @phpstan-type Mine array{topic: string, tag: string, name: string, color: string, sources: list<string>, puzzles: int}
 */
final readonly class OpeningChoices
{
    public function __construct(
        private Connection $connection,
        #[Autowire(service: 'doctrine.dbal.catalog_connection')]
        private Connection $catalog,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return array{mine: list<Mine>, all: list<Family>}
     */
    public function of(User $user): array
    {
        $all = $this->families();
        $byTag = [];
        foreach ($all as $family) {
            $byTag[$family['tag']] = $family;
        }

        /** @var array<string, array{tag: string, color: string, sources: array<string, true>, weight: int}> $mine */
        $mine = [];
        $add = static function (string $tag, string $color, string $source, int $weight) use (&$mine): void {
            $key = $color.':'.$tag;
            $mine[$key] ??= ['tag' => $tag, 'color' => $color, 'sources' => [], 'weight' => 0];
            $mine[$key]['sources'][$source] = true;
            $mine[$key]['weight'] += $weight;
        };
        foreach ($this->fromRepertoires($user) as [$tag, $color, $weight]) {
            $add($tag, $color, 'repertoire', $weight);
        }
        foreach ($this->fromGames($user) as [$tag, $color, $weight]) {
            $add($tag, $color, 'games', $weight);
        }

        $kept = [];
        foreach ($mine as $choice) {
            $family = $byTag[$choice['tag']] ?? null;
            $puzzles = null === $family ? 0 : ('white' === $choice['color'] ? $family['white'] : $family['black']);
            if (null !== $family && $puzzles >= StreakRules::MIN_OPENING_PUZZLES) {
                $kept[] = [$choice, $family['name'], $puzzles];
            }
        }
        // The most present first.
        usort($kept, static fn (array $a, array $b): int => [$b[0]['weight'], $a[1]] <=> [$a[0]['weight'], $b[1]]);

        return [
            'mine' => array_map(static fn (array $kept): array => [
                'topic' => Topic::opening($kept[0]['tag'], 'white' === $kept[0]['color'] ? 'w' : 'b')->key,
                'tag' => $kept[0]['tag'],
                'name' => $kept[1],
                'color' => $kept[0]['color'],
                'sources' => array_keys($kept[0]['sources']),
                'puzzles' => $kept[2],
            ], $kept),
            'all' => $all,
        ];
    }

    /**
     * The catalogue's families with at least MIN_OPENING_PUZZLES selectable puzzles for a side,
     * by name. "white": the puzzles where the player has White.
     *
     * @return list<Family>
     */
    public function families(): array
    {
        $counts = [];
        foreach ($this->catalog->fetchAllAssociative(
            'SELECT opening_family, solver_color, COUNT(*) AS n FROM puzzle
              WHERE opening_family IS NOT NULL AND selectable = 1 GROUP BY opening_family, solver_color',
        ) as $row) {
            if (!\is_string($row['opening_family']) || '' === $row['opening_family']) {
                continue;
            }
            $side = 'w' === $row['solver_color'] ? 'white' : 'black';
            $counts[$row['opening_family']][$side] = is_numeric($row['n']) ? (int) $row['n'] : 0;
        }
        $names = $this->names();
        $families = [];
        foreach ($counts as $tag => $sides) {
            $white = $sides['white'] ?? 0;
            $black = $sides['black'] ?? 0;
            if (max($white, $black) < StreakRules::MIN_OPENING_PUZZLES) {
                continue;
            }
            $tag = (string) $tag;
            $families[] = ['tag' => $tag, 'name' => $names[$tag] ?? str_replace('_', ' ', $tag), 'white' => $white, 'black' => $black];
        }
        usort($families, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        return $families;
    }

    /**
     * The families' readable names ("Queen's Pawn Game"), by tag, from the opening names.
     *
     * @return array<string, string>
     */
    private function names(): array
    {
        $names = [];
        foreach ($this->connection->fetchFirstColumn("SELECT DISTINCT SUBSTRING_INDEX(name, ':', 1) FROM repertoire_opening") as $name) {
            if (\is_string($name)) {
                $names[OpeningFamily::tag($name)] ??= trim($name);
            }
        }

        return $names;
    }

    /**
     * The families of the user's repertoires: their named positions, weighted by how many.
     *
     * @return list<array{string, string, int}> tag, colour (white, black), weight
     */
    private function fromRepertoires(User $user): array
    {
        $found = [];
        foreach ($this->connection->fetchAllAssociative(
            'SELECT r.color, o.name, COUNT(*) AS n
               FROM repertoire_position p
               JOIN repertoire r ON r.id = p.repertoire_id
               JOIN repertoire_opening o ON o.epd_hash = p.fen_hash
              WHERE p.user_id = ?
              GROUP BY r.color, o.name',
            [$user->getId()->toBinary()],
            [ParameterType::BINARY],
        ) as $row) {
            if (!\is_string($row['name']) || !\in_array($row['color'], ['white', 'black'], true)) {
                continue;
            }
            $key = $row['color'].':'.OpeningFamily::tag($row['name']);
            $found[$key] = ($found[$key] ?? 0) + (is_numeric($row['n']) ? (int) $row['n'] : 0);
        }

        return self::split($found);
    }

    /**
     * The families of the user's Lichess games already read: the tailored session's opening sets,
     * and the games of their repertoire build still kept, weighted by the games.
     *
     * @return list<array{string, string, int}> tag, colour (white, black), weight
     */
    private function fromGames(User $user): array
    {
        $found = [];
        foreach ($this->connection->fetchAllAssociative(
            'SELECT opening_family, opening_color FROM woodpecker_set WHERE user_id = ? AND opening_family IS NOT NULL',
            [$user->getId()->toBinary()],
            [ParameterType::BINARY],
        ) as $row) {
            if (\is_string($row['opening_family']) && \in_array($row['opening_color'], ['white', 'black'], true)) {
                $key = $row['opening_color'].':'.$row['opening_family'];
                $found[$key] = ($found[$key] ?? 0) + 1;
            }
        }
        $games = $this->connection->fetchOne(
            'SELECT games FROM repertoire_auto_build WHERE user_id = ? AND expires_at > ? ORDER BY created_at DESC LIMIT 1',
            [$user->getId()->toBinary(), $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s')],
            [ParameterType::BINARY, ParameterType::STRING],
        );
        $decoded = \is_string($games) ? json_decode($games, true) : null;
        foreach (\is_array($decoded) ? $decoded : [] as $game) {
            if (\is_array($game) && \is_string($game['fam'] ?? null) && \in_array($game['c'] ?? null, ['white', 'black'], true)) {
                $key = $game['c'].':'.OpeningFamily::tag($game['fam']);
                $found[$key] = ($found[$key] ?? 0) + 1;
            }
        }

        return self::split($found);
    }

    /**
     * @param array<string, int> $found by "colour:tag"
     *
     * @return list<array{string, string, int}>
     */
    private static function split(array $found): array
    {
        $list = [];
        foreach ($found as $key => $weight) {
            [$color, $tag] = explode(':', (string) $key, 2);
            if ('' !== $tag) {
                $list[] = [$tag, $color, $weight];
            }
        }

        return $list;
    }
}
