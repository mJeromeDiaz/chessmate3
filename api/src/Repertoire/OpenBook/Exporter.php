<?php

declare(strict_types=1);

namespace App\Repertoire\OpenBook;

use App\Chess\Rules;
use App\Entity\AuthIdentity;
use App\Entity\Repertoire\Repertoire;
use App\Enum\AuthProvider;
use App\Repertoire\Graph\GraphReader;
use App\Repertoire\Pgn\Exporter as PgnExporter;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * A repertoire as an OpenBook backup (docs/REPERTOIRE.md, "OpenBook"), the format
 * App\Repertoire\Import\OpenBookReader reads: the repertoire's side holds, for each position where
 * the user is to move, the prepared move (SAN) and its comment as notes; the other side is empty.
 * Keys are FENs without the move counters, the en passant square set after any double push (as
 * OpenBook writes them), reached along the canonical path; they are sorted. "user" is the linked
 * Lichess account's username, else empty; no "srs" nor "sets".
 *
 * @phpstan-import-type MoveRow from GraphReader
 */
final readonly class Exporter
{
    public function __construct(
        private GraphReader $reader,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    public function export(Repertoire $repertoire): string
    {
        /** @var array<string, list<MoveRow>> $out */
        $out = [];
        foreach ($this->reader->moves($repertoire) as $move) {
            $out[$move['from']][] = $move;
        }
        $userTurn = $repertoire->getColor()->turn();

        $entries = [];
        $queue = [[$this->reader->rootId($repertoire), Rules::INITIAL_FEN]];
        for ($head = 0; $head < \count($queue); ++$head) {
            [$positionId, $fen] = $queue[$head];
            foreach ($out[$positionId] ?? [] as $move) {
                $rules = Rules::fromFen($fen);
                if ($rules->sideToMove() === $userTurn) {
                    $entries[self::key($rules->fen())] = ['moves' => [$move['san']], 'notes' => $move['comment'] ?? ''];
                }
                // The canonical tree: every position once, along the path that gives its key.
                if ($move['canonical'] && null !== $rules->playUci($move['uci'])) {
                    $queue[] = [$move['to'], $rules->fen()];
                }
            }
        }
        ksort($entries, \SORT_STRING);

        $sides = ['white' => new \stdClass(), 'black' => new \stdClass()];
        $sides[$repertoire->getColor()->value] = (object) $entries;

        return json_encode([
            'version' => 1,
            'date' => $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z'),
            'user' => $this->lichessUsername($repertoire),
            'repertoire' => $sides,
            'srs' => ['white' => new \stdClass(), 'black' => new \stdClass()],
            'sets' => [],
        ], \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE)."\n";
    }

    /** The PGN export's file name, as JSON. */
    public static function fileName(Repertoire $repertoire): string
    {
        return substr(PgnExporter::fileName($repertoire), 0, -4).'.json';
    }

    /** A FEN without its move counters. */
    private static function key(string $fen): string
    {
        return implode(' ', \array_slice(explode(' ', $fen), 0, 4));
    }

    private function lichessUsername(Repertoire $repertoire): string
    {
        $identity = $this->entityManager->getRepository(AuthIdentity::class)->findOneBy(['user' => $repertoire->getUser(), 'provider' => AuthProvider::Lichess]);
        $username = $identity?->getMetadata()['username'] ?? null;

        return \is_string($username) ? $username : '';
    }
}
