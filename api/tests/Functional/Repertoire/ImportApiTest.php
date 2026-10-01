<?php

declare(strict_types=1);

namespace App\Tests\Functional\Repertoire;

use App\Chess\Rules;
use App\Repertoire\Import\Message\AnalyzeImport;
use App\Repertoire\Import\Message\AnalyzeImportHandler;
use App\Repertoire\Import\Message\ApplyImport;
use App\Repertoire\Import\Message\ApplyImportHandler;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * /repertoires/imports: create, preview, apply; small imports during the request, big ones by
 * a worker (the in-memory transport of the tests, handled here by hand).
 *
 * @phpstan-type ImportJson array{id: string, status: string, progress: int, source: string, label: string|null, games: int|null, suggestedName: string|null, suggestedColor: string|null, error: string|null, errorLine: int|null, repertoireId: string|null, preview: array<string, mixed>|null}
 */
final class ImportApiTest extends RepertoireWebTestCase
{
    public function testASmallFileIsAnalysedPreviewedAndAppliedDuringTheRequest(): void
    {
        $alice = $this->createUser('alice@example.com');
        $created = $this->createImport($alice, (string) file_get_contents(__DIR__.'/../../Fixtures/Chess/openbook-white.pgn'), 'openbook.pgn');

        self::assertSame(['analyzed', 100, 'pgn', 'openbook.pgn', 1, 'OpenBook white repertoire', 'white', null], [$created['status'], $created['progress'], $created['source'], $created['label'], $created['games'], $created['suggestedName'], $created['suggestedColor'], $created['error']]);

        $preview = $this->import($alice, $created['id'])['preview'];
        self::assertIsArray($preview);
        self::assertSame(['repertoireId' => null, 'color' => 'white', 'lines' => 3, 'filePositions' => 40, 'newPositions' => 39, 'newMoves' => 39, 'knownMoves' => 0, 'positionsAfter' => 40, 'maxPositions' => 5000, 'warnings' => [], 'conflicts' => [], 'replaced' => 0, 'trashedPositions' => 0], $preview);
        $asBlack = $this->import($alice, $created['id'], '?color=black')['preview'];
        self::assertIsArray($asBlack);
        self::assertSame('black', $asBlack['color']);

        $applied = $this->apply($alice, $created['id'], ['name' => 'OpenBook', 'color' => 'white']);
        self::assertSame('done', $applied['status']);
        self::assertIsString($applied['repertoireId']);
        $graph = $this->graph($alice, $applied['repertoireId']);
        self::assertSame('OpenBook', $graph['name']);
        self::assertCount(40, $graph['positions']);
        self::assertCount(5, $graph['segments']);

        // Done: it cannot be applied twice.
        self::assertSame(409, $this->api('POST', '/api/repertoires/imports/'.$created['id'].'/apply', $alice, ['name' => 'Again', 'color' => 'white'])->getStatusCode());
    }

    public function testAnOpenBookBackupIsImportedLikeItsPgnAndExportedBackIdentically(): void
    {
        $alice = $this->createUser('alice@example.com');
        $backup = (string) file_get_contents(__DIR__.'/../../Fixtures/Chess/openbook-backup.json');
        $created = $this->createImport($alice, $backup, 'openbook-backup.json');

        self::assertSame(['analyzed', 'openbook', 'white', 'OpenBook'], [$created['status'], $created['source'], $created['suggestedColor'], $created['suggestedName']]);
        $preview = $this->import($alice, $created['id'])['preview'];
        self::assertIsArray($preview);
        self::assertSame([3, 40, 39, []], [$preview['lines'], $preview['positionsAfter'], $preview['newMoves'], $preview['warnings']]);
        $asBlack = $this->import($alice, $created['id'], '?color=black')['preview'];
        self::assertIsArray($asBlack);
        self::assertSame(0, $asBlack['newMoves'], 'the black side of this backup is empty');

        $applied = $this->apply($alice, $created['id'], ['name' => 'OpenBook', 'color' => 'white']);
        self::assertIsString($applied['repertoireId']);
        self::assertCount(5, $this->graph($alice, $applied['repertoireId'])['segments']);

        $this->api('GET', '/api/repertoires/'.$applied['repertoireId'].'/export?format=openbook', $alice);
        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/json; charset=UTF-8', $response->headers->get('Content-Type'));
        self::assertSame('attachment; filename=openbook.json', $response->headers->get('Content-Disposition'));
        /** @var array{version: int, date: string, user: string, repertoire: array{white: array<string, mixed>, black: array<string, mixed>}, srs: array<string, mixed>, sets: list<mixed>} $exported */
        $exported = json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        /** @var array{repertoire: array{white: array<string, mixed>}} $original */
        $original = json_decode($backup, true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame($original['repertoire']['white'], $exported['repertoire']['white'], 'same keys (en passant squares included), moves and notes');
        self::assertSame([1, '', [], ['white' => [], 'black' => []], []], [$exported['version'], $exported['user'], $exported['repertoire']['black'], $exported['srs'], $exported['sets']]);
        self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{3}Z$/', $exported['date']);
        self::assertStringContainsString('"black": {}', (string) $response->getContent(), 'objects, as OpenBook writes them');
        self::assertSame(404, $this->api('GET', '/api/repertoires/'.$applied['repertoireId'].'/export?format=docx', $alice)->getStatusCode());
    }

    public function testAnOpenBookSideIsChosenByTheRepertoiresColorWhenMerging(): void
    {
        $alice = $this->createUser('alice@example.com');
        $black = $this->createRepertoireVia($alice, 'Noirs', 'black');
        $backup = json_encode(['version' => 1, 'repertoire' => [
            'white' => ['rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR w KQkq -' => ['moves' => ['e4'], 'notes' => '']],
            'black' => ['rnbqkbnr/pppppppp/8/8/3P4/8/PPP1PPPP/RNBQKBNR b KQkq d3' => ['moves' => ['d5'], 'notes' => 'Classique']],
        ]], \JSON_THROW_ON_ERROR);
        $created = $this->createImport($alice, $backup);

        $preview = $this->import($alice, $created['id'], '?repertoireId='.$black['id'])['preview'];
        self::assertIsArray($preview);
        self::assertSame(2, $preview['newMoves'], '1.d4 d5, not 1.e4');
        $this->apply($alice, $created['id'], ['repertoireId' => $black['id']]);
        $moves = $this->graph($alice, $black['id'])['moves'];
        self::assertSame(['d4' => null, 'd5' => 'Classique'], array_column($moves, 'comment', 'san'));
    }

    public function testMergingIntoARepertoireShowsTheConflictsAndFollowsTheChoices(): void
    {
        $alice = $this->createUser('alice@example.com');
        $repertoire = $this->createRepertoireVia($alice, 'Blancs', 'white');
        $this->playLine($alice, $repertoire['id'], ['e2e4', 'e7e5']);
        $version = $this->graph($alice, $repertoire['id'])['version'];
        $created = $this->createImport($alice, '1. d4 d5 (1... Nf6 2. c4) 2. c4 (2. Bf4) * ');

        // By default the repertoire's 1.e4 stays: nothing of the file is imported.
        $preview = $this->import($alice, $created['id'], '?repertoireId='.$repertoire['id'])['preview'];
        self::assertIsArray($preview);
        self::assertSame($repertoire['id'], $preview['repertoireId']);
        self::assertSame([3, 0, 0, 0], [$preview['positionsAfter'], $preview['newMoves'], $preview['replaced'], $preview['trashedPositions']]);
        /** @var list<array{fen: string, path: list<string>, candidates: list<array{uci: string, san: string, origin: string}>, choice: string}> $conflicts */
        $conflicts = $preview['conflicts'];
        self::assertCount(1, $conflicts);
        self::assertSame([[], 'e2e4'], [$conflicts[0]['path'], $conflicts[0]['choice']]);
        self::assertSame([['uci' => 'e2e4', 'san' => 'e4', 'origin' => 'existing'], ['uci' => 'd2d4', 'san' => 'd4', 'origin' => 'file']], $conflicts[0]['candidates']);

        // Choosing 1.d4 reveals the conflict after 1...d5, the file's first move by default.
        $root = Rules::initial()->normalizedFen();
        $preview = $this->import($alice, $created['id'], '?'.http_build_query(['repertoireId' => $repertoire['id'], 'choices' => [$root => 'd2d4']]))['preview'];
        self::assertIsArray($preview);
        /** @var list<array{fen: string, path: list<string>, choice: string}> $conflicts */
        $conflicts = $preview['conflicts'];
        self::assertSame([[], ['d4', 'd5']], array_column($conflicts, 'path'));
        self::assertSame(['d2d4', 'c2c4'], array_column($conflicts, 'choice'));
        self::assertSame([6, 1, 2], [$preview['positionsAfter'], $preview['replaced'], $preview['trashedPositions']], 'd4, d5, Nf6, c4 twice; 1.e4 e5 to the trash');

        $applied = $this->apply($alice, $created['id'], ['repertoireId' => $repertoire['id'], 'baseVersion' => $version, 'choices' => [$root => 'd2d4', $conflicts[1]['fen'] => 'c1f4']]);
        self::assertSame('done', $applied['status']);
        $graph = $this->graph($alice, $repertoire['id']);
        $sans = array_column($graph['moves'], 'san');
        sort($sans);
        self::assertSame(['Bf4', 'Nf6', 'c4', 'd4', 'd5'], $sans, '2.c4 after 1...d5 not chosen, after 1...Nf6 alone');
        self::assertSame($version + 1, $graph['version']);
        self::assertSame([['imported', 'e4']], array_map(static fn (array $suite): array => [$suite['reason'], $suite['san']], $this->trash($alice, $repertoire['id'])));
    }

    public function testAStalePreviewIsRefusedAndTheImportStaysAvailable(): void
    {
        $alice = $this->createUser('alice@example.com');
        $repertoire = $this->createRepertoireVia($alice);
        $version = $this->graph($alice, $repertoire['id'])['version'];
        $created = $this->createImport($alice, '1. d4 d5 *');
        $this->playLine($alice, $repertoire['id'], ['e2e4']);

        self::assertSame(409, $this->api('POST', '/api/repertoires/imports/'.$created['id'].'/apply', $alice, ['repertoireId' => $repertoire['id'], 'baseVersion' => $version])->getStatusCode());
        self::assertSame('analyzed', $this->import($alice, $created['id'])['status']);
        self::assertSame('done', $this->apply($alice, $created['id'], ['repertoireId' => $repertoire['id']])['status']);
    }

    public function testAnUnreadableFileFailsWithItsReason(): void
    {
        $alice = $this->createUser('alice@example.com');

        $syntax = $this->createImport($alice, "1. e4 e5\n2. Nf3 (Nc6\n*");
        self::assertSame(['failed', 'syntax'], [$syntax['status'], $syntax['error']]);
        self::assertIsInt($syntax['errorLine']);
        self::assertNull($syntax['preview']);

        $illegal = $this->createImport($alice, '1. e5 *');
        self::assertSame(['failed', 'empty'], [$illegal['status'], $illegal['error']]);

        self::assertSame(422, $this->api('POST', '/api/repertoires/imports', $alice, ['pgn' => str_repeat(' ', 1_048_577)])->getStatusCode());
        self::assertSame(422, $this->api('POST', '/api/repertoires/imports', $alice, ['pgn' => ''])->getStatusCode());
    }

    public function testWarningsTellWhatWasLeftOut(): void
    {
        $alice = $this->createUser('alice@example.com');
        $created = $this->createImport($alice, "1. e4 e5 2. Ke3 *\n\n[FEN \"8/8/8/4k3/8/8/8/4K2R w K - 0 40\"]\n\n40. Rh5+ *");

        $preview = $this->import($alice, $created['id'])['preview'];
        self::assertIsArray($preview);
        self::assertSame([
            ['type' => 'illegal_move', 'game' => 1, 'move' => '2.Ke3'],
            ['type' => 'start_not_found', 'game' => 2],
        ], $preview['warnings']);
        self::assertSame(1, $preview['lines']);
    }

    public function testABigImportIsAnalysedAndAppliedByAWorker(): void
    {
        $alice = $this->createUser('alice@example.com');
        // Over 100 KB (a long comment) and over 500 new positions.
        $pgn = '{'.str_repeat('Padding. ', 12_000).'} '.self::games(620);
        $created = $this->createImport($alice, $pgn);

        self::assertSame(['analyzing', 0], [$created['status'], $created['progress']]);
        $message = $this->sent(AnalyzeImport::class);
        self::getContainer()->get(AnalyzeImportHandler::class)($message);
        $this->entityManager->clear();
        $analyzed = $this->import($alice, $created['id']);
        self::assertSame(['analyzed', 100], [$analyzed['status'], $analyzed['progress']]);
        self::getContainer()->get(AnalyzeImportHandler::class)($message);

        $queued = $this->apply($alice, $created['id'], ['name' => 'Big', 'color' => 'white']);
        self::assertSame('applying', $queued['status']);
        self::assertNull($queued['repertoireId']);
        $apply = $this->sent(ApplyImport::class);
        self::getContainer()->get(ApplyImportHandler::class)($apply);
        $this->entityManager->clear();

        $done = $this->import($alice, $created['id']);
        self::assertSame(['done', 100], [$done['status'], $done['progress']]);
        self::assertIsString($done['repertoireId']);
        self::assertGreaterThan(500, \count($this->graph($alice, $done['repertoireId'])['positions']));
        self::getContainer()->get(ApplyImportHandler::class)($apply);
        self::assertSame(1, $this->repertoireCount(), 'idempotent');
    }

    public function testAnotherUsersImportOrRepertoireIsNotFoundAndImportsExpire(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $created = $this->createImport($alice, '1. e4 *');
        $bobs = $this->createRepertoireVia($bob);

        self::assertSame(404, $this->api('GET', '/api/repertoires/imports/'.$created['id'], $bob)->getStatusCode());
        self::assertSame(404, $this->api('POST', '/api/repertoires/imports/'.$created['id'].'/apply', $bob, ['name' => 'X', 'color' => 'white'])->getStatusCode());
        self::assertSame(404, $this->api('GET', '/api/repertoires/imports/'.$created['id'].'?repertoireId='.$bobs['id'], $alice)->getStatusCode());
        self::assertSame(404, $this->api('POST', '/api/repertoires/imports/'.$created['id'].'/apply', $alice, ['repertoireId' => $bobs['id']])->getStatusCode());
        self::assertSame(422, $this->api('POST', '/api/repertoires/imports/'.$created['id'].'/apply', $alice, ['repertoireId' => $bobs['id'], 'name' => 'Both', 'color' => 'white'])->getStatusCode(), 'one destination');

        $this->entityManager->getConnection()->executeStatement('UPDATE repertoire_import SET expires_at = ?', [(new \DateTimeImmutable('-1 minute'))->format('Y-m-d H:i:s')]);
        self::assertSame(404, $this->api('GET', '/api/repertoires/imports/'.$created['id'], $alice)->getStatusCode());
        $this->createImport($alice, '1. d4 *');
        self::assertEquals(1, $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM repertoire_import'), 'purged at the next import');
    }

    public function testImportsAreRateLimited(): void
    {
        $alice = $this->createUser('alice@example.com');
        /** @var RateLimiterFactory $limiter */
        $limiter = self::getContainer()->get('limiter.repertoire_import');
        $limiter->create($alice->getId()->toRfc4122())->consume(10);

        self::assertSame(429, $this->api('POST', '/api/repertoires/imports', $alice, ['pgn' => '1. e4 *'])->getStatusCode());
    }

    /**
     * Deterministic random games (a few choices near the root, more later): about $positions
     * distinct positions.
     */
    private static function games(int $positions): string
    {
        mt_srand(7);
        $seen = [];
        $games = [];
        while (\count($seen) < $positions) {
            $rules = Rules::initial();
            $sans = [];
            for ($ply = 0; $ply < 20 && \count($seen) < $positions; ++$ply) {
                $moves = $rules->legalMoves();
                if ([] === $moves) {
                    break;
                }
                $spread = min(\count($moves), $ply < 4 ? 2 : 4);
                // White (the user) always plays the same move in a position: one prepared move.
                $pick = $moves[0 === $ply % 2 ? crc32($rules->normalizedFen()) % $spread : mt_rand(0, $spread - 1)];
                $san = (string) $rules->playUci(Rules::uci($pick))?->san;
                $sans[] = (0 === $ply % 2 ? (intdiv($ply, 2) + 1).'. ' : '').$san;
                $seen[$rules->normalizedFen()] = true;
            }
            $games[] = implode(' ', $sans).' *';
        }

        return implode("\n\n", $games);
    }

    /**
     * @return ImportJson
     */
    private function createImport(\App\Entity\User $user, string $pgn, ?string $fileName = null): array
    {
        $response = $this->api('POST', '/api/repertoires/imports', $user, ['pgn' => $pgn, 'fileName' => $fileName]);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());

        /** @var ImportJson */
        return $this->json($response);
    }

    /**
     * @return ImportJson
     */
    private function import(\App\Entity\User $user, string $id, string $query = ''): array
    {
        $response = $this->api('GET', '/api/repertoires/imports/'.$id.$query, $user);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        /** @var ImportJson */
        return $this->json($response);
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return ImportJson
     */
    private function apply(\App\Entity\User $user, string $id, array $body): array
    {
        $response = $this->api('POST', '/api/repertoires/imports/'.$id.'/apply', $user, $body);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        /** @var ImportJson */
        return $this->json($response);
    }

    /**
     * The message of that class sent during the last request (the kernel, and its in-memory
     * transport, restart with each request).
     *
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function sent(string $class): object
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $messages = array_values(array_filter(array_map(static fn ($envelope): object => $envelope->getMessage(), $transport->getSent()), static fn (object $m): bool => $m instanceof $class));
        self::assertCount(1, $messages);
        self::assertInstanceOf($class, $messages[0]);

        return $messages[0];
    }

    private function repertoireCount(): int
    {
        $count = $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM repertoire');

        return is_numeric($count) ? (int) $count : -1;
    }
}
