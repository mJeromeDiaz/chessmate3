<?php

declare(strict_types=1);

namespace App\Tests\Functional\Notation;

use App\Chess\Rules;
use App\Entity\User;
use App\Tests\Functional\Repertoire\RepertoireWebTestCase;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Response;

/**
 * The line exercise of the notation module (docs/NOTATION.md, § 3, « line »): a line of the
 * player's repertoire of 10 segments at least, played up to its last 4 half-moves, to write; the
 * repertoires fit for it; the prerequisite.
 */
final class NotationLineTest extends RepertoireWebTestCase
{
    /** Ten 6-ply lines after 1.d4: the trunk and ten deviations, eleven segments. */
    private const LINES = [
        ['d2d4', 'd7d5', 'c2c4', 'e7e6', 'b1c3', 'g8f6'],
        ['d2d4', 'g8f6', 'c2c4', 'g7g6', 'b1c3', 'f8g7'],
        ['d2d4', 'f7f5', 'g2g3', 'g8f6', 'f1g2', 'g7g6'],
        ['d2d4', 'c7c5', 'd4d5', 'e7e5', 'e2e4', 'd7d6'],
        ['d2d4', 'g7g6', 'e2e4', 'f8g7', 'b1c3', 'd7d6'],
        ['d2d4', 'd7d6', 'e2e4', 'g8f6', 'b1c3', 'g7g6'],
        ['d2d4', 'b7b6', 'e2e4', 'c8b7', 'f1d3', 'e7e6'],
        ['d2d4', 'c7c6', 'e2e4', 'd7d5', 'b1c3', 'd5e4'],
        ['d2d4', 'b8c6', 'g1f3', 'd7d5', 'c2c4', 'c8g4'],
        ['d2d4', 'e7e5', 'd4e5', 'b8c6', 'g1f3', 'd8e7'],
    ];

    public function testALineOfTheRepertoireIsCompletedInWriting(): void
    {
        $alice = $this->createUser('alice@example.com');
        $repertoire = $this->createRepertoireVia($alice);
        foreach (self::LINES as $line) {
            $this->playLine($alice, $repertoire['id'], $line);
        }

        $overview = $this->overview($alice);
        self::assertSame(['lineMinSegments' => 10, 'lineWritePlies' => 4], $overview['rules']);
        self::assertSame([[$repertoire['id'], 11, true]], array_map(
            static fn (array $r): array => [$r['id'], $r['segments'], $r['eligible']],
            $overview['repertoires'],
        ));

        $run = $this->start($alice, ['kind' => 'line', 'repertoireId' => $repertoire['id']]);
        /** @var array{id: string, data: array{kind: string, fen: string, plies: int, shown: list<array{uci: string, san: string}>, label: string|null, orientation: string}} $item */
        $item = $this->json($this->api('POST', '/api/training/runs/'.$run.'/next', $alice))['item'];
        self::assertSame(['line', 4, 'white'], [$item['data']['kind'], $item['data']['plies'], $item['data']['orientation']]);
        self::assertCount(2, $item['data']['shown'], '6 plies: 2 shown, 4 to write.');
        self::assertSame('d2d4', $item['data']['shown'][0]['uci']);
        $lines = array_values(array_filter(self::LINES, static fn (array $l): bool => $l[1] === $item['data']['shown'][1]['uci']));
        self::assertCount(1, $lines, 'One of the lines played.');
        $line = $lines[0] ?? [];

        $rules = Rules::fromFen($item['data']['fen']);
        $sans = array_map(static fn (string $uci): string => $rules->playUci($uci)->san ?? $uci, \array_slice($line, 2));
        $response = $this->api('POST', '/api/training/runs/'.$run.'/submission', $alice, ['itemId' => $item['id'], 'written' => ['2. '.implode(' ', $sans)]]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        /** @var array{result: array{success: bool}} $answered */
        $answered = $this->json($response);
        self::assertTrue($answered['result']['success']);

        // The next line is another one.
        /** @var array{id: string, data: array{shown: list<array{uci: string}>}} $next */
        $next = $this->json($this->api('POST', '/api/training/runs/'.$run.'/next', $alice))['item'];
        self::assertNotSame($item['data']['shown'][1]['uci'], $next['data']['shown'][1]['uci']);
        self::assertSame(1, $this->countLines($alice));
    }

    public function testTheRepertoireIsAPrerequisite(): void
    {
        $alice = $this->createUser('alice@example.com');
        $small = $this->createRepertoireVia($alice);
        $this->playLine($alice, $small['id'], self::LINES[0]);

        // Without a repertoire of 10 segments: no line to complete.
        $refused = $this->startResponse($alice, ['kind' => 'line']);
        self::assertSame(409, $refused->getStatusCode());
        self::assertStringContainsString('no_repertoire', (string) $refused->getContent());
        self::assertFalse($this->overview($alice)['repertoires'][0]['eligible']);

        // Another player's repertoire, or an option out of place.
        $bob = $this->createUser('bob@example.com');
        self::assertSame(404, $this->startResponse($bob, ['kind' => 'line', 'repertoireId' => $small['id']])->getStatusCode());
        self::assertSame(422, $this->startResponse($alice, ['kind' => 'play', 'repertoireId' => $small['id']])->getStatusCode());
        self::assertSame(422, $this->startResponse($alice, ['kind' => 'line', 'repertoireId' => 'nope'])->getStatusCode());
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return string the run's id
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
            'budgetSeconds' => 300,
            'config' => $config,
        ]);
    }

    /**
     * @return array{rules: array{lineMinSegments: int, lineWritePlies: int}, repertoires: list<array{id: string, segments: int, eligible: bool}>}
     */
    private function overview(User $user): array
    {
        $response = $this->api('GET', '/api/notation', $user);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        /** @var array{rules: array{lineMinSegments: int, lineWritePlies: int}, repertoires: list<array{id: string, segments: int, eligible: bool}>} */
        return $this->json($response);
    }

    /** The user's notation items answered (repertoire lines). */
    private function countLines(User $user): int
    {
        $count = self::getContainer()->get(Connection::class)->fetchOne(
            "SELECT COUNT(*) FROM notation_attempt WHERE user_id = ? AND kind = 'line' AND status <> 'pending'",
            [$user->getId()->toBinary()],
        );

        return is_numeric($count) ? (int) $count : 0;
    }
}
