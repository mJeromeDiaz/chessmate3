<?php

declare(strict_types=1);

namespace App\Tests\Functional\Notation;

use App\Chess\Rules;
use App\Entity\User;
use App\Notation\Games\LegendaryGames;
use App\Tests\Functional\Repertoire\RepertoireWebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * The legendary games of the notation module (docs/NOTATION.md, § 3): 6 half-moves at a time,
 * played once read (game_play) or written once seen (game_write), the game going on from where its
 * last item ended, then starting again; the games listed; the options checked.
 *
 * @phpstan-type GameItem array{id: string, data: array{kind: string, fen: string, plies: int, moves: list<array{uci: string, san: string}>, shown: list<array{uci: string, san: string}>, label: string|null, orientation: string}}
 */
final class NotationGameTest extends RepertoireWebTestCase
{
    public function testAGameIsPlayedSixHalfMovesAtATimeThenStartsAgain(): void
    {
        $alice = $this->createUser('alice@example.com');
        $opera = self::getContainer()->get(LegendaryGames::class)->find(1);
        self::assertNotNull($opera);

        $run = $this->start($alice, ['kind' => 'game_play', 'gameId' => 1]);
        $item = $this->item($alice, $run);
        self::assertSame(['game_play', [], 6, 'white'], [$item['data']['kind'], $item['data']['shown'], $item['data']['plies'], $item['data']['orientation']]);
        self::assertSame(\array_slice($opera->moves, 0, 6), array_column($item['data']['moves'], 'uci'));
        self::assertSame('Paul Morphy – Duc de Brunswick / Comte Isouad de Vauvenargues, Paris 1858', $item['data']['label']);

        // Six chunks: 6, 6, 6, 6, 6, then the last 3 half-moves.
        for ($chunk = 0; $chunk < 6; ++$chunk) {
            if ($chunk > 0) {
                $item = $this->item($alice, $run);
            }
            self::assertSame(\array_slice($opera->moves, 0, 6 * $chunk), array_column($item['data']['shown'], 'uci'), "Chunk $chunk goes on from the last one.");
            $this->submitOk($alice, $run, $item['id'], ['moves' => array_column($item['data']['moves'], 'uci')]);
        }
        self::assertCount(3, $item['data']['moves'], '33 half-moves: the last chunk has 3.');

        // Over: the game chosen starts again.
        self::assertSame([], $this->item($alice, $run)['data']['shown']);
    }

    public function testAChunkIsWrittenOnceSeen(): void
    {
        $alice = $this->createUser('alice@example.com');
        $run = $this->start($alice, ['kind' => 'game_write', 'gameId' => 1]);
        $item = $this->item($alice, $run);
        $rules = Rules::fromFen($item['data']['fen']);
        $sans = array_map(static fn (string $uci): string => $rules->playUci($uci)->san ?? $uci, array_column($item['data']['moves'], 'uci'));
        self::assertSame(['e4', 'e5', 'Nf3', 'd6', 'd4', 'Bg4'], $sans);

        $right = $this->submitOk($alice, $run, $item['id'], ['written' => ['1. e4 e5 2. Nf3 d6 3. d4 Bg4']]);
        self::assertTrue($right['success']);
        $next = $this->item($alice, $run);
        $wrong = $this->submitOk($alice, $run, $next['id'], ['written' => ['4. dxe5 Bxf3 5. Qxf3 dxe5 6. Bc4 Nc6']]);
        self::assertSame([false, 5, 'wrong'], [$wrong['success'], $wrong['data']['mistakeAt'], $wrong['data']['reason']]);
    }

    public function testTheGamesAndTheOptions(): void
    {
        $alice = $this->createUser('alice@example.com');
        $overview = $this->json($this->api('GET', '/api/notation', $alice));
        /** @var list<array{id: int, white: string, plies: int}> $games */
        $games = $overview['games'] ?? [];
        self::assertCount(7, $games);
        self::assertSame([1, 'Paul Morphy', 33], [$games[0]['id'], $games[0]['white'], $games[0]['plies']]);

        self::assertSame(422, $this->startResponse($alice, ['kind' => 'play', 'gameId' => 1])->getStatusCode(), 'A game for the game exercises only.');
        self::assertSame(422, $this->startResponse($alice, ['kind' => 'game_play', 'gameId' => 99])->getStatusCode());
        // Without a game chosen: any of them, from its start.
        $item = $this->item($alice, $this->start($alice, ['kind' => 'game_write']));
        self::assertSame([], $item['data']['shown']);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function start(User $user, array $config): string
    {
        $response = $this->startResponse($user, $config);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());
        $id = $this->json($response)['id'] ?? null;
        self::assertIsString($id);

        return $id;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function startResponse(User $user, array $config): Response
    {
        return $this->api('POST', '/api/training/runs', $user, [
            'module' => 'notation',
            'subjectId' => $user->getId()->toRfc4122(),
            'budgetSeconds' => 600,
            'config' => $config,
        ]);
    }

    /**
     * @return GameItem
     */
    private function item(User $user, string $runId): array
    {
        $item = $this->json($this->api('POST', '/api/training/runs/'.$runId.'/next', $user))['item'] ?? null;
        self::assertIsArray($item, 'The run served no item.');

        /** @var GameItem */
        return $item;
    }

    /**
     * @param array<string, mixed> $report
     *
     * @return array{success: bool, data: array{mistakeAt: int|null, reason: string|null}}
     */
    private function submitOk(User $user, string $runId, string $itemId, array $report): array
    {
        $response = $this->api('POST', '/api/training/runs/'.$runId.'/submission', $user, ['itemId' => $itemId] + $report);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $result = $this->json($response)['result'] ?? null;
        self::assertIsArray($result);

        /** @var array{success: bool, data: array{mistakeAt: int|null, reason: string|null}} */
        return $result;
    }
}
