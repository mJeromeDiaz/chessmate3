<?php

declare(strict_types=1);

namespace App\Repertoire\Opening;

use App\Chess\Pgn\Parser;
use App\Chess\Position\PositionKey;
use App\Chess\Rules;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Loads lichess-org/chess-openings (data/chess-openings/*.tsv, CC0) into repertoire_opening:
 * each line's moves are replayed to compute its UCI moves and normalized FEN. Idempotent: an
 * upsert on the FEN digest, then the openings gone from the files are removed. When two lines name
 * the same position, the first one (file order: ECO code) is kept.
 */
final class OpeningSynchronizer
{
    public const FILES = ['a.tsv', 'b.tsv', 'c.tsv', 'd.tsv', 'e.tsv'];

    public function __construct(
        private readonly Connection $connection,
        private readonly Parser $parser,
        #[Autowire('%kernel.project_dir%/data/chess-openings')]
        private readonly string $directory,
    ) {
    }

    /**
     * @return array{loaded: int, duplicates: int, removed: int}
     *
     * @throws \RuntimeException unreadable file, illegal move in the data
     */
    public function sync(?string $directory = null): array
    {
        $directory ??= $this->directory;
        $openings = [];
        $duplicates = 0;
        foreach (self::FILES as $file) {
            $path = $directory.'/'.$file;
            $handle = @fopen($path, 'r');
            if (false === $handle) {
                throw new \RuntimeException(sprintf('Cannot read %s.', $path));
            }
            fgets($handle);
            while (false !== $line = fgets($handle)) {
                $columns = explode("\t", rtrim($line, "\r\n"));
                if (3 !== \count($columns)) {
                    continue;
                }
                [$eco, $name, $pgn] = $columns;
                [$fen, $uci] = $this->replay($pgn);
                $key = PositionKey::of($fen);
                if (isset($openings[$key->hash])) {
                    ++$duplicates;
                    continue;
                }
                $openings[$key->hash] = [$eco, $name, $pgn, implode(' ', $uci), $fen];
            }
            fclose($handle);
        }

        $this->connection->transactional(function () use ($openings): void {
            foreach ($openings as $hash => [$eco, $name, $pgn, $uci, $fen]) {
                $this->connection->executeStatement(
                    'INSERT INTO repertoire_opening (eco, name, pgn, uci, epd, epd_hash) VALUES (?, ?, ?, ?, ?, ?) AS new
                     ON DUPLICATE KEY UPDATE eco = new.eco, name = new.name, pgn = new.pgn, uci = new.uci, epd = new.epd',
                    [$eco, $name, $pgn, $uci, $fen, (string) $hash],
                    [ParameterType::STRING, ParameterType::STRING, ParameterType::STRING, ParameterType::STRING, ParameterType::STRING, ParameterType::BINARY],
                );
            }
        });
        $removed = 0;
        foreach ($this->connection->fetchFirstColumn('SELECT epd_hash FROM repertoire_opening') as $hash) {
            if (\is_string($hash) && !isset($openings[$hash])) {
                $removed += (int) $this->connection->executeStatement('DELETE FROM repertoire_opening WHERE epd_hash = ?', [$hash], [ParameterType::BINARY]);
            }
        }

        return ['loaded' => \count($openings), 'duplicates' => $duplicates, 'removed' => $removed];
    }

    /**
     * @return array{string, list<string>} normalized FEN reached, UCI moves
     */
    private function replay(string $pgn): array
    {
        $games = $this->parser->parse($pgn);
        $rules = Rules::initial();
        $uci = [];
        $node = $games[0]->root ?? null;
        while (null !== $node = $node?->mainChild()) {
            $move = $rules->playSan($node->san) ?? throw new \RuntimeException(sprintf('Illegal move %s in "%s".', $node->san, $pgn));
            $uci[] = Rules::uci($move);
        }

        return [$rules->normalizedFen(), $uci];
    }
}
