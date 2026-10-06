<?php

declare(strict_types=1);

namespace App\Puzzle\Import;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Writes imported puzzles into the catalogue's database in plain SQL, by multi-row statements (no
 * entity is loaded). Upsert on `lichess_id`: a puzzle already there keeps its `id`, which attempts
 * and Woodpecker sets reference, and its `random_key`; only the figures Lichess updates every month
 * are refreshed (docs/PUZZLE_IMPORT.md, § 7). `selectable` and the selection index are left to
 * `app:puzzle:rebuild-selection`.
 */
final class PuzzleWriter
{
    private const COLUMNS = '(lichess_id, fen, moves, rating, rating_deviation, popularity, nb_plays, themes, opening_tags, game_url, daily_date, random_key, selectable)';
    private const PLACEHOLDERS = '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
    private const ON_DUPLICATE = <<<'SQL'
         AS new ON DUPLICATE KEY UPDATE
            rating = new.rating, rating_deviation = new.rating_deviation,
            popularity = new.popularity, nb_plays = new.nb_plays,
            themes = new.themes, opening_tags = new.opening_tags, daily_date = new.daily_date
        SQL;

    public function __construct(
        #[Autowire(service: 'doctrine.dbal.catalog_connection')]
        private readonly Connection $connection,
    ) {
    }

    public function countPuzzles(): int
    {
        return $this->count('SELECT COUNT(*) FROM puzzle');
    }

    public function countSelectable(): int
    {
        return $this->count('SELECT COUNT(*) FROM puzzle WHERE selectable = 1');
    }

    /**
     * The Lichess ids among these that the catalogue already holds.
     *
     * @param list<string> $lichessIds
     *
     * @return array<string, true>
     */
    public function existing(array $lichessIds): array
    {
        if ([] === $lichessIds) {
            return [];
        }
        $found = $this->connection->fetchFirstColumn(
            'SELECT lichess_id FROM puzzle WHERE lichess_id IN (:ids)',
            ['ids' => $lichessIds],
            ['ids' => ArrayParameterType::STRING],
        );

        return array_fill_keys(array_filter($found, \is_string(...)), true);
    }

    /**
     * @param list<CsvRow> $rows
     */
    public function upsert(array $rows): void
    {
        if ([] === $rows) {
            return;
        }

        $params = [];
        foreach ($rows as $row) {
            array_push(
                $params,
                $row->lichessId,
                $row->fen,
                $row->moves,
                $row->rating,
                $row->ratingDeviation,
                $row->popularity,
                $row->nbPlays,
                json_encode($row->themes, \JSON_THROW_ON_ERROR),
                null === $row->openingTags ? null : json_encode($row->openingTags, \JSON_THROW_ON_ERROR),
                $row->gameUrl,
                $row->dailyDate,
                random_int(0, 0xFFFFFFFF),
                (int) $row->isSelectable(),
            );
        }

        $this->connection->executeStatement(
            'INSERT INTO puzzle '.self::COLUMNS.' VALUES '
                .implode(', ', array_fill(0, \count($rows), self::PLACEHOLDERS))
                .self::ON_DUPLICATE,
            $params,
        );
    }

    private function count(string $sql): int
    {
        $count = $this->connection->fetchOne($sql);

        return is_numeric($count) ? (int) $count : 0;
    }
}
