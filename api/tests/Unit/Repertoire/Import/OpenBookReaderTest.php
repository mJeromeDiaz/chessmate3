<?php

declare(strict_types=1);

namespace App\Tests\Unit\Repertoire\Import;

use App\Repertoire\Import\ImportedTree;
use App\Repertoire\Import\ImportRejectedException;
use App\Repertoire\Import\OpenBookReader;
use App\Repertoire\Import\PgnAnalyzer;
use App\Repertoire\Limits;
use PHPUnit\Framework\TestCase;

final class OpenBookReaderTest extends TestCase
{
    private const FIXTURES = __DIR__.'/../../../Fixtures/Chess/';

    public function testABackupIsRecognizedByItsContent(): void
    {
        self::assertTrue(OpenBookReader::recognizes((string) file_get_contents(self::FIXTURES.'openbook-backup.json')));
        self::assertTrue(OpenBookReader::recognizes("\u{FEFF}  {\"version\": 1, \"repertoire\": {}}"));
        self::assertFalse(OpenBookReader::recognizes('{A comment first} 1. e4 e5 *'), 'a PGN can start with a comment');
        self::assertFalse(OpenBookReader::recognizes('{"version": 1}'));
        self::assertFalse(OpenBookReader::recognizes('1. e4 *'));
    }

    public function testTheOpenBookBackupGivesTheSameTreeAsItsPgn(): void
    {
        [$white, $black, $suggested] = $this->read((string) file_get_contents(self::FIXTURES.'openbook-backup.json'));
        $pgn = (new PgnAnalyzer(new Limits()))->analyze((string) file_get_contents(self::FIXTURES.'openbook-white.pgn'));

        self::assertSame('white', $suggested);
        self::assertSame([], $black->edges);
        self::assertSame([], $white->warnings);
        self::assertSame(['white', 'OpenBook'], [$white->color, $white->name]);
        $moves = static fn (ImportedTree $tree): array => array_map(static fn (array $edge): string => $edge['from'].' '.$edge['uci'].' '.$edge['san'], $tree->edges);
        $expected = $moves($pgn);
        $actual = $moves($white);
        sort($expected);
        sort($actual);
        self::assertSame($expected, $actual, 'the opponent moves are found back');
        self::assertEqualsCanonicalizing($pgn->positions, $white->positions);
    }

    public function testMovesNotesAndWhatIsLeftOut(): void
    {
        $json = json_encode(['version' => 1, 'repertoire' => ['black' => [
            // After 1.e4 (raw FEN: en passant square after the double push).
            'rnbqkbnr/pppppppp/8/8/4P3/8/PPPP1PPP/RNBQKBNR b KQkq e3' => ['moves' => ['c5', 'e5'], 'notes' => "  Sicilienne\u{7} ou e5 "],
            // After 1.d4: 1...Nf6, then 2.c4 or 2.Nf3 reach the next ones.
            'rnbqkbnr/pppppppp/8/8/3P4/8/PPP1PPPP/RNBQKBNR b KQkq d3' => ['moves' => ['Nf6'], 'notes' => ''],
            'rnbqkb1r/pppppppp/5n2/8/2PP4/8/PP2PPPP/RNBQKBNR b KQkq c3' => ['moves' => ['Qxh7']],
            'rnbqkb1r/pppppppp/5n2/8/3P4/5N2/PPP1PPPP/RNBQKB1R b KQkq -' => ['moves' => ['g6'], 'notes' => ''],
            // After 1.h4 g6 2.h5: unreachable, the file has no move after 1.h4.
            'rnbqkbnr/pppppp1p/6p1/7P/8/8/PPPPPPP1/RNBQKBNR b KQkq -' => ['moves' => ['Bg7']],
            // White to move: not a position of the black repertoire.
            'rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR w KQkq -' => ['moves' => ['e4']],
            'not a fen' => ['moves' => ['e4']],
        ]]], \JSON_THROW_ON_ERROR);

        [$white, $black, $suggested] = $this->read($json);

        self::assertSame('black', $suggested);
        self::assertSame([], $white->edges);
        // Breadth first; both moves of the file after 1.e4 (the planner makes it a conflict).
        self::assertSame(['d4 reply', 'e4 reply', 'Nf6 -', 'c5 Sicilienne ou e5', 'e5 -', 'c4 reply', 'Nf3 reply', 'g6 -'], array_map(
            static fn (array $edge): string => $edge['san'].' '.(null === $edge['comment'] ? ('b' === $black->positions[$edge['from']] ? '-' : 'reply') : $edge['comment']),
            $black->edges,
        ));
        self::assertSame([
            ['type' => ImportedTree::INVALID_POSITION, 'game' => 1, 'move' => 'rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR w KQkq -'],
            ['type' => ImportedTree::INVALID_POSITION, 'game' => 1, 'move' => 'not a fen'],
            ['type' => ImportedTree::ILLEGAL_MOVE, 'game' => 1, 'move' => '2...Qxh7'],
            ['type' => ImportedTree::UNREACHABLE, 'game' => 1, 'count' => 1],
        ], $black->warnings);
    }

    public function testUnreadableOrEmptyBackupsAreRefused(): void
    {
        foreach (['{"version": 2, "repertoire": {}}' => ImportRejectedException::SYNTAX, '{"version": 1, "repertoire": {"white": {}, "black": {}}}' => ImportRejectedException::EMPTY, '{"version": 1, "repertoire": ' => ImportRejectedException::SYNTAX] as $json => $reason) {
            try {
                $this->read($json);
                self::fail('Accepted: '.$json);
            } catch (ImportRejectedException $e) {
                self::assertSame($reason, $e->reason, $json);
            }
        }
    }

    public function testTheStoredSidesAreReadBackByColor(): void
    {
        [$white, $black, $suggested] = $this->read((string) file_get_contents(self::FIXTURES.'openbook-backup.json'));
        $stored = ImportedTree::sidesToJson($white, $black, $suggested);

        self::assertCount(39, ImportedTree::fromStored($stored)->edges, 'the suggested side');
        self::assertSame([], ImportedTree::fromStored($stored, 'black')->edges);
        self::assertSame($white->toArray(), ImportedTree::fromStored($stored, 'white')->toArray());
    }

    /**
     * @return array{ImportedTree, ImportedTree, 'white'|'black'}
     */
    private function read(string $json): array
    {
        return (new OpenBookReader(new Limits()))->read($json);
    }
}
