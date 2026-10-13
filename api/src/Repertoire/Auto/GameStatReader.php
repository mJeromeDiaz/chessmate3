<?php

declare(strict_types=1);

namespace App\Repertoire\Auto;

use App\Entity\Repertoire\Repertoire;
use Doctrine\DBAL\Connection;

/**
 * The statistics of the games a repertoire was built from (repertoire_game_stat), for the editor:
 * one row per move, identified by its position (normalized FEN) and UCI, so that they follow the
 * moves through the edits (docs/REPERTOIRE.md, "Repertoire from my games").
 *
 * @phpstan-type GameStatRow array{fen: string, uci: string, games: int, wins: int, draws: int, losses: int, analysed: int, errored: int, thinkCs: int|null, moveAnalysed: int, moveErrors: int, bestUci: string|null, evalCp: int|null, evalMate: int|null}
 */
final readonly class GameStatReader
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * @return list<GameStatRow>
     */
    public function rows(Repertoire $repertoire): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT fen, uci, games, wins, draws, losses, analysed, errored, think_cs, move_analysed, move_errors, best_uci, eval_cp, eval_mate
               FROM repertoire_game_stat WHERE repertoire_id = ?',
            [$repertoire->getId()->toBinary()],
        );
        $int = static fn (mixed $value): int => is_numeric($value) ? (int) $value : 0;
        $nullableInt = static fn (mixed $value): ?int => is_numeric($value) ? (int) $value : null;

        return array_map(static fn (array $row): array => [
            'fen' => \is_string($row['fen']) ? $row['fen'] : '',
            'uci' => \is_string($row['uci']) ? $row['uci'] : '',
            'games' => $int($row['games']),
            'wins' => $int($row['wins']),
            'draws' => $int($row['draws']),
            'losses' => $int($row['losses']),
            'analysed' => $int($row['analysed']),
            'errored' => $int($row['errored']),
            'thinkCs' => $nullableInt($row['think_cs']),
            'moveAnalysed' => $int($row['move_analysed']),
            'moveErrors' => $int($row['move_errors']),
            'bestUci' => \is_string($row['best_uci']) ? $row['best_uci'] : null,
            'evalCp' => $nullableInt($row['eval_cp']),
            'evalMate' => $nullableInt($row['eval_mate']),
        ], $rows);
    }
}
