<?php

declare(strict_types=1);

namespace App\Repertoire\Pgn;

use App\Chess\Pgn\Game;
use App\Chess\Pgn\Node;
use App\Chess\Pgn\Writer;
use App\Entity\Repertoire\Repertoire;
use App\Repertoire\Graph\GraphReader;

/**
 * A repertoire as one PGN game (docs/REPERTOIRE.md, "Export"): the canonical tree from the
 * initial position, moves in display order (the first one is the main line, the others are
 * variations), comments and NAGs. A transposition is written and ends its line, as in the editor:
 * the position it reaches continues where it is canonical. Importing the file back gives the same
 * graph.
 *
 * @phpstan-import-type MoveRow from GraphReader
 */
final readonly class Exporter
{
    public function __construct(
        private GraphReader $reader,
        private Writer $writer = new Writer(),
    ) {
    }

    public function export(Repertoire $repertoire): string
    {
        /** @var array<string, list<MoveRow>> $out */
        $out = [];
        foreach ($this->reader->moves($repertoire) as $move) {
            $out[$move['from']][] = $move;
        }
        $out = array_map(
            /**
             * @param list<MoveRow> $moves
             *
             * @return list<MoveRow>
             */
            static function (array $moves): array {
                usort($moves, static fn (array $a, array $b): int => [$a['sortOrder'], $a['id']] <=> [$b['sortOrder'], $b['id']]);

                return $moves;
            },
            $out,
        );

        $game = new Game();
        $game->tags = [
            'Event' => $repertoire->getName(),
            'Site' => 'Don\'t Stay Rooky',
            'Orientation' => $repertoire->getColor()->value,
            'Result' => '*',
        ];
        $game->result = '*';

        // Iterative: each canonical move is expanded once (the canonical tree has no cycle).
        $nodes = [$game->root];
        $positions = [$this->reader->rootId($repertoire)];
        while ([] !== $nodes) {
            $parent = array_pop($nodes);
            $positionId = (string) array_pop($positions);
            foreach ($out[$positionId] ?? [] as $move) {
                $node = new Node($move['san']);
                $node->nags = $move['nags'];
                $node->comment = $move['comment'];
                $parent->children[] = $node;
                if ($move['canonical']) {
                    $nodes[] = $node;
                    $positions[] = $move['to'];
                }
            }
        }

        return $this->writer->write($game);
    }

    /** A file name for the download: the repertoire's name, ASCII only. */
    public static function fileName(Repertoire $repertoire): string
    {
        $ascii = (string) iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $repertoire->getName());
        $slug = trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $ascii), '-');

        return ('' === $slug ? 'repertoire' : strtolower($slug)).'.pgn';
    }
}
