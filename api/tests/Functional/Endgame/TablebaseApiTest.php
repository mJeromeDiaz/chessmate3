<?php

declare(strict_types=1);

namespace App\Tests\Functional\Endgame;

use App\Tests\Functional\Woodpecker\WoodpeckerWebTestCase;
use App\Tests\Unit\Endgame\AnswerTest;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * GET /api/endgames/tablebase (docs/ENDGAMES.md, § 2).
 */
final class TablebaseApiTest extends WoodpeckerWebTestCase
{
    private const KRK = '8/8/8/4k3/8/8/8/R3K3 w - - 0 1';

    /** @var list<string> */
    private array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();
        self::getContainer()->get('repertoire.explorer_cache')->clear();
        self::getContainer()->get('cache.rate_limiter')->clear();
    }

    public function testTheVerdictOfEachMoveAndTheBestDefence(): void
    {
        $this->lichess(new MockResponse(json_encode(AnswerTest::BODY, \JSON_THROW_ON_ERROR), ['response_headers' => ['content-type' => 'application/json']]));
        $alice = $this->createUserIn('alice@example.com');

        $response = $this->api('GET', '/api/endgames/tablebase?fen='.rawurlencode('8/8/8/4k3/8/8/8/R3K3 w - - 3 9'), $alice);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        /** @var array{fen: string, known: bool, result: string|null, dtm: int|null, moves: list<array<string, mixed>>, defence: list<string>} $body */
        $body = $this->json($response);
        self::assertSame('8/8/8/4k3/8/8/8/R3K3 w - -', $body['fen']);
        self::assertTrue($body['known']);
        self::assertSame('win', $body['result']);
        self::assertSame(13, $body['dtm']);
        self::assertSame(['uci' => 'a1a7', 'result' => 'win', 'dtm' => -12, 'dtz' => -12, 'zeroing' => false], $body['moves'][0]);
        self::assertSame('draw', $body['moves'][2]['result']);
        self::assertSame(['a1a7'], $body['defence']);

        // Asked again: from the endgame database.
        self::assertSame(200, $this->api('GET', '/api/endgames/tablebase?fen='.rawurlencode(self::KRK), $alice)->getStatusCode());
        self::assertCount(1, $this->calls);
    }

    public function testPositionsOutsideTheTablebaseAndInvalidOnes(): void
    {
        $this->lichess();
        $alice = $this->createUserIn('alice@example.com');

        $start = $this->json($this->api('GET', '/api/endgames/tablebase?fen='.rawurlencode('rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR w KQkq - 0 1'), $alice));
        self::assertFalse($start['known']);
        self::assertSame([], $start['moves']);
        self::assertSame(422, $this->api('GET', '/api/endgames/tablebase?fen=nonsense', $alice)->getStatusCode());
        self::assertSame([], $this->calls);
    }

    public function testLichessUnavailableIsTold(): void
    {
        $this->lichess(new MockResponse('', ['http_code' => 503]));
        $alice = $this->createUserIn('alice@example.com');

        $response = $this->api('GET', '/api/endgames/tablebase?fen='.rawurlencode(self::KRK), $alice);

        self::assertSame(503, $response->getStatusCode());
        self::assertSame('down', $response->headers->get('X-Lichess-Unavailable'));
    }

    public function testSignedInPlayersOnly(): void
    {
        $this->client->request('GET', '/api/endgames/tablebase?fen='.rawurlencode(self::KRK));

        self::assertSame(401, $this->client->getResponse()->getStatusCode());
    }

    private function lichess(MockResponse ...$responses): void
    {
        // A kernel reboot would bring the real client back.
        $this->client->disableReboot();
        self::getContainer()->set('lichess_tablebase.client', new MockHttpClient(function (string $method, string $url) use (&$responses): MockResponse {
            $this->calls[] = $url;

            return array_shift($responses) ?? throw new \LogicException('Unexpected call: '.$url);
        }, 'https://tablebase.lichess.ovh'));
    }
}
