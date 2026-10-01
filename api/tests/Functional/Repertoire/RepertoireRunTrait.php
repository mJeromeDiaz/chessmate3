<?php

declare(strict_types=1);

namespace App\Tests\Functional\Repertoire;

use App\Chess\Pgn\Node;
use App\Chess\Pgn\Parser;
use App\Chess\Position\PositionKey;
use App\Chess\Rules;
use App\Entity\Repertoire\Position;
use App\Entity\Repertoire\Repertoire;
use App\Entity\User;
use App\Enum\Repertoire\Color;
use App\Repertoire\Graph\GraphEditor;
use App\Repertoire\RepertoireManager;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

/**
 * Plays timed repertoire tests through the training API, and builds repertoires through the real
 * services (for RepertoireWebTestCase subclasses).
 *
 * @phpstan-type Played array{uci: string, san: string}
 * @phpstan-type Label array{opening: array{eco: string, name: string}|null, move: string|null}
 * @phpstan-type Start array{unit: string, orientation: string, context: list<Played>, deviation: bool, label: Label, rank: int, round: int, retry: bool, newRound: bool}
 * @phpstan-type Item array{id: string, type: string, data: array{unitId: string, repertoireId: string, segmentId: string, index: int, total: int, play: list<Played>, fen: string, ply: int, unit: string, orientation: string, label: Label, start: Start|null}}
 * @phpstan-type Result array{itemId: string, success: bool, data: array{status: string, correct: bool|null, expected: Played|null, comment: string|null, rating: string|null, unitDone: bool, unitSuccess: bool|null, retry: bool}}
 * @phpstan-type Summary array{durationMs: int, itemCount: int, successCount: int, failureCount: int, metrics: array<string, mixed>}
 * @phpstan-type RunJson array{id: string, status: string, closeReason: string|null, summary: Summary|null}
 */
trait RepertoireRunTrait
{
    /**
     * Plays the next unit to its end, right (or with a mistake on its first move).
     *
     * @param Item|null $first its first item, already served
     *
     * @return string the unit's key segment (its last segment for a line)
     */
    private function playUnit(User $user, string $runId, bool $fail = false, ?array $first = null): string
    {
        $item = $first ?? $this->next($user, $runId);
        self::assertNotNull($item['data']['start'], 'a unit starts');
        $segment = $item['data']['segmentId'];
        while (true) {
            $segment = $item['data']['segmentId'];
            $result = $fail ? $this->submit($user, $runId, $item, 'a2a3' === $this->expected($item) ? 'h2h3' : 'a2a3') : $this->answer($user, $runId, $item);
            $fail = false;
            if ($result['data']['unitDone']) {
                return $segment;
            }
            $item = $this->next($user, $runId);
        }
    }

    /**
     * @param Item $item
     *
     * @return Result
     */
    private function answer(User $user, string $runId, array $item): array
    {
        return $this->submit($user, $runId, $item, $this->expected($item));
    }

    /**
     * The prepared move, read from the database as the server knows it.
     *
     * @param Item $item
     */
    private function expected(array $item): string
    {
        $uci = $this->connection()->fetchOne(
            "SELECT m.uci FROM repertoire_move m JOIN repertoire_position p ON p.id = m.from_position_id WHERE p.fen = ? AND m.repertoire_id = ? AND m.role = 'reference'",
            [$item['data']['fen'], Uuid::fromString($item['data']['repertoireId'])->toBinary()],
            [ParameterType::STRING, ParameterType::BINARY],
        );
        self::assertIsString($uci);

        return $uci;
    }

    /**
     * @param Item $item
     *
     * @return Result
     */
    private function submit(User $user, string $runId, array $item, string $uci, ?int $thinkMs = null): array
    {
        $response = $this->api('POST', '/api/training/runs/'.$runId.'/submission', $user, ['itemId' => $item['id'], 'moves' => [$uci], 'thinkMs' => $thinkMs]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        /** @var array{result: Result} $step */
        $step = $this->json($response);

        return $step['result'];
    }

    /**
     * @return Item
     */
    private function next(User $user, string $runId): array
    {
        $step = $this->step($user, $runId);
        self::assertNotNull($step['item'], 'an item is served');

        return $step['item'];
    }

    /**
     * @return array{run: RunJson, item: Item|null}
     */
    private function step(User $user, string $runId): array
    {
        $response = $this->api('POST', '/api/training/runs/'.$runId.'/next', $user);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        /** @var array{run: RunJson, item: Item|null} */
        return $this->json($response);
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return RunJson
     */
    private function start(User $user, array $config, int $budgetSeconds = 1200): array
    {
        $response = $this->startResponse($user, $config, budgetSeconds: $budgetSeconds);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());

        /** @var RunJson */
        return $this->json($response);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function startResponse(User $user, array $config, ?string $subjectId = null, int $budgetSeconds = 1200): Response
    {
        return $this->api('POST', '/api/training/runs', $user, ['module' => 'repertoire', 'subjectId' => $subjectId ?? $user->getId()->toRfc4122(), 'budgetSeconds' => $budgetSeconds, 'config' => $config]);
    }

    /**
     * @return RunJson
     */
    private function stop(User $user, string $runId): array
    {
        $response = $this->api('POST', '/api/training/runs/'.$runId.'/stop', $user);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        /** @var RunJson */
        return $this->json($response);
    }

    private function repertoire(User $user, string $pgn, Color $color = Color::White): Repertoire
    {
        $repertoire = self::getContainer()->get(RepertoireManager::class)->create($user, 'Test', $color);
        foreach ((new Parser())->parse($pgn) as $game) {
            $this->tree($repertoire, $game->root, $this->rootOf($repertoire));
        }

        return $repertoire;
    }

    private function openBook(User $user): Repertoire
    {
        return $this->repertoire($user, (string) file_get_contents(__DIR__.'/../../Fixtures/Chess/openbook-white.pgn'));
    }

    private function tree(Repertoire $repertoire, Node $node, string $positionId): void
    {
        foreach ($node->children as $child) {
            $fen = $this->fenOf($positionId);
            $move = Rules::fromFen($fen)->playSan($child->san) ?? throw new \LogicException('Illegal '.$child->san);
            $change = self::getContainer()->get(GraphEditor::class)->addMove($repertoire->getUser(), $repertoire->getId(), Uuid::fromString($positionId), Rules::uci($move));
            $to = $this->connection()->fetchOne('SELECT to_position_id FROM repertoire_move WHERE id = ?', [Uuid::fromString((string) $change->moveId)->toBinary()], [ParameterType::BINARY]);
            self::assertIsString($to);
            $this->tree($repertoire, $child, Uuid::fromBinary($to)->toRfc4122());
        }
    }

    private function rootOf(Repertoire $repertoire): string
    {
        $position = $this->entityManager->getRepository(Position::class)->findOneBy(['repertoire' => $repertoire, 'fenHash' => PositionKey::of(Rules::initial()->normalizedFen())->hash]);

        return $position?->getId()->toRfc4122() ?? throw new \LogicException('No root.');
    }

    private function fenOf(string $positionId): string
    {
        $fen = $this->connection()->fetchOne('SELECT fen FROM repertoire_position WHERE id = ?', [Uuid::fromString($positionId)->toBinary()], [ParameterType::BINARY]);
        self::assertIsString($fen);

        return $fen;
    }

    private function positionAfter(Repertoire $repertoire, string $path): string
    {
        $rules = Rules::initial();
        foreach (explode(' ', $path) as $san) {
            $rules->playSan($san) ?? throw new \LogicException($san);
        }
        $id = $this->connection()->fetchOne('SELECT id FROM repertoire_position WHERE repertoire_id = ? AND fen = ?', [$repertoire->getId()->toBinary(), $rules->normalizedFen()], [ParameterType::BINARY]);
        self::assertIsString($id);

        return Uuid::fromBinary($id)->toRfc4122();
    }

    private function moveId(Repertoire $repertoire, string $path, string $san): string
    {
        $rules = Rules::initial();
        foreach (array_filter(explode(' ', $path)) as $step) {
            $rules->playSan($step) ?? throw new \LogicException($step);
        }
        $from = $rules->normalizedFen();
        $uci = Rules::uci($rules->playSan($san) ?? throw new \LogicException($san));
        $id = $this->connection()->fetchOne(
            'SELECT m.id FROM repertoire_move m JOIN repertoire_position p ON p.id = m.from_position_id WHERE m.repertoire_id = ? AND p.fen = ? AND m.uci = ?',
            [$repertoire->getId()->toBinary(), $from, $uci],
            [ParameterType::BINARY, ParameterType::STRING, ParameterType::STRING],
        );
        self::assertIsString($id);

        return Uuid::fromBinary($id)->toRfc4122();
    }

    private function segmentOf(Repertoire $repertoire, string $path, string $san): string
    {
        $segment = $this->connection()->fetchOne('SELECT segment_id FROM repertoire_move WHERE id = ?', [Uuid::fromString($this->moveId($repertoire, $path, $san))->toBinary()], [ParameterType::BINARY]);
        self::assertIsString($segment);

        return Uuid::fromBinary($segment)->toRfc4122();
    }

    /**
     * @return list<array{status: string, firstErrorPly: int|null, positionsGraded: int, moves: list<string>, unit: string, unitId: string}>
     */
    private function presentations(string $where = '1 = 1'): array
    {
        $rows = $this->connection()->fetchAllAssociative('SELECT status, first_error_ply, positions_graded, moves, unit, HEX(unit_id) unit_id FROM repertoire_presentation WHERE '.$where.' ORDER BY started_at, id');

        return array_map(static function (array $row): array {
            self::assertIsString($row['status']);
            self::assertIsNumeric($row['positions_graded']);
            self::assertIsString($row['moves']);
            self::assertIsString($row['unit']);
            self::assertIsString($row['unit_id']);
            /** @var list<string> $moves */
            $moves = json_decode($row['moves'], true, flags: \JSON_THROW_ON_ERROR);

            return [
                'status' => $row['status'],
                'firstErrorPly' => is_numeric($row['first_error_ply']) ? (int) $row['first_error_ply'] : null,
                'positionsGraded' => (int) $row['positions_graded'],
                'moves' => $moves,
                'unit' => $row['unit'],
                'unitId' => $row['unit_id'],
            ];
        }, $rows);
    }

    private function connection(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
