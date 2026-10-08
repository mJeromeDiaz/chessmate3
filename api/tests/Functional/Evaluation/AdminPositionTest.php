<?php

declare(strict_types=1);

namespace App\Tests\Functional\Evaluation;

use App\Entity\User;
use App\Tests\Functional\Woodpecker\WoodpeckerWebTestCase;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Positions to evaluate entered by admins (docs/EVALUATION.md): admins only, a legal and unique
 * FEN with a move to play, 1 to 3 ideas, optional plan, tip and tag; deactivated rather than
 * deleted once played; checked against Lichess before saving (Lichess simulated).
 */
final class AdminPositionTest extends WoodpeckerWebTestCase
{
    private const LUCENA = '1K1k4/1P6/8/8/8/8/r7/2R5 w - - 0 1';
    private const START = 'rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR w KQkq - 0 1';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connection()->executeStatement('UPDATE evaluation_position SET active = 0');
        $this->admin = $this->createUserIn('admin@example.com');
        $this->admin->setRoles(['ROLE_ADMIN']);
        $this->entityManager->flush();
    }

    public function testAdminsOnly(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        self::assertSame(403, $this->api('GET', '/api/admin/evaluation/positions', $alice)->getStatusCode());
        self::assertSame(403, $this->api('POST', '/api/admin/evaluation/positions', $alice, self::body())->getStatusCode());
        self::assertSame(403, $this->api('POST', '/api/admin/evaluation/verification', $alice, ['fen' => self::START, 'evalCp' => 20])->getStatusCode());
    }

    public function testAPositionIsEnteredChangedListedAndDeletedUntilPlayed(): void
    {
        // Any move counters: stored normalized.
        $created = $this->createOk(self::body(['fen' => '1K1k4/1P6/8/8/8/8/r7/2R5 w - - 12 57']));
        self::assertSame([self::LUCENA, 'white', '+−', 2, false, 'simplify', 1500, true, 0], [
            $created['fen'], $created['turn'], $created['engine'], $created['category'], $created['nearBorder'], $created['plan'], $created['rating'], $created['active'], $created['played'],
        ]);
        self::assertSame(['Le fou c8 est bloqué par ses pions.'], $created['ideas']);

        $second = $this->createOk(self::body(['fen' => self::START, 'evalCp' => 190, 'plan' => null, 'tip' => '  ', 'tag' => null, 'ideas' => ['Une.', 'Deux.', 'Trois.']]));
        $secondId = $second['id'];
        $createdId = $created['id'];
        self::assertIsString($secondId);
        self::assertIsString($createdId);
        self::assertSame([true, null, null, null, '+1,9'], [$second['nearBorder'], $second['plan'], $second['tip'], $second['tag'], $second['engine']]);

        $list = $this->json($this->api('GET', '/api/admin/evaluation/positions', $this->admin));
        self::assertSame(2, $list['totalItems'] ?? null);

        // Changed and deactivated.
        $response = $this->api('PUT', '/api/admin/evaluation/positions/'.$secondId, $this->admin, self::body(['fen' => self::START, 'evalCp' => 30, 'active' => false]));
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(['+0,3', 0, false], [$this->json($response)['engine'] ?? null, $this->json($response)['category'] ?? null, $this->json($response)['active'] ?? null]);
        $active = $this->json($this->api('GET', '/api/admin/evaluation/positions?active=true', $this->admin));
        self::assertSame(1, $active['totalItems'] ?? null);

        // Never played: deleted.
        self::assertSame(204, $this->api('DELETE', '/api/admin/evaluation/positions/'.$secondId, $this->admin)->getStatusCode());
        self::assertSame(404, $this->api('GET', '/api/admin/evaluation/positions/'.$secondId, $this->admin)->getStatusCode());

        // Played (served in a run): deactivate it instead.
        $run = $this->api('POST', '/api/training/runs', $this->admin, ['module' => 'evaluation', 'subjectId' => $this->admin->getId()->toRfc4122(), 'budgetSeconds' => 600, 'config' => ['count' => 3, 'seconds' => 60, 'elo' => 1500, 'side' => 'both']]);
        self::assertSame(201, $run->getStatusCode(), (string) $run->getContent());
        self::assertSame(1, $this->json($this->api('GET', '/api/admin/evaluation/positions/'.$createdId, $this->admin))['played'] ?? null);
        self::assertSame(409, $this->api('DELETE', '/api/admin/evaluation/positions/'.$createdId, $this->admin)->getStatusCode());
    }

    public function testWhatIsRefused(): void
    {
        $this->createOk(self::body());
        foreach ([
            'already in the catalogue' => [409, ['fen' => self::LUCENA]],
            'illegal FEN' => [422, ['fen' => 'not a fen']],
            'mate: nothing to evaluate' => [422, ['fen' => 'rnb1kbnr/pppp1ppp/8/4p3/6Pq/5P2/PPPPP2P/RNBQKBNR w KQkq - 1 3']],
            'no idea' => [422, ['ideas' => []]],
            'four ideas' => [422, ['ideas' => ['1', '2', '3', '4']]],
            'beyond won' => [422, ['evalCp' => 20_000]],
            'unknown plan' => [422, ['plan' => 'castle']],
            'unknown tag' => [422, ['tag' => 'gambit']],
            'Elo too low' => [422, ['rating' => 500]],
        ] as $case => [$status, $changes]) {
            $response = $this->api('POST', '/api/admin/evaluation/positions', $this->admin, self::body($changes));
            self::assertSame($status, $response->getStatusCode(), $case.': '.$response->getContent());
        }
    }

    public function testAPositionIsCheckedAgainstLichessBeforeSaving(): void
    {
        $mock = new MockHttpClient(static function (string $method, string $url): MockResponse {
            $json = ['response_headers' => ['content-type' => 'application/json']];

            return match (true) {
                str_starts_with($url, 'https://tablebase.lichess.ovh/standard?') => new MockResponse('{"category":"win"}', $json),
                str_starts_with($url, 'https://lichess.org/api/cloud-eval?') => new MockResponse('{"depth":40,"knodes":1,"pvs":[{"moves":"e2e4","cp":18}]}', $json),
                default => throw new \LogicException('Unexpected call: '.$url),
            };
        });
        self::getContainer()->get('repertoire.explorer_cache')->clear();
        self::getContainer()->set('lichess_tablebase.client', $mock);
        self::getContainer()->set('lichess_api.client', $mock);

        $lucena = $this->check(self::LUCENA, 10_000);
        self::assertSame(['ok', 'tablebase', 'win', 2], [$lucena['verdict'], $lucena['source'], $lucena['lichess'], $lucena['lichessCategory']]);
        self::assertSame('mismatch', $this->check(self::LUCENA, 0)['verdict'], 'A won endgame entered as equal.');
        $start = $this->check(self::START, 150);
        self::assertSame(['mismatch', 'cloud', 0], [$start['verdict'], $start['source'], $start['lichessCategory']]);
        self::assertSame('ok', $this->check(self::START, 20)['verdict']);

        self::assertSame(422, $this->api('POST', '/api/admin/evaluation/verification', $this->admin, ['fen' => 'not a fen', 'evalCp' => 0])->getStatusCode());
        $count = $this->connection()->fetchOne('SELECT COUNT(*) FROM evaluation_position WHERE active = 1');
        self::assertTrue(is_numeric($count));
        self::assertSame(0, (int) $count, 'Nothing saved.');
    }

    /**
     * @param array<string, mixed> $changes
     *
     * @return array<string, mixed>
     */
    private static function body(array $changes = []): array
    {
        return $changes + [
            'fen' => self::LUCENA,
            'evalCp' => 10_000,
            'ideas' => ['Le fou c8 est bloqué par ses pions.'],
            'plan' => 'simplify',
            'tip' => 'Le roi noir peut-il revenir ?',
            'tag' => 'endgame',
            'rating' => 1500,
            'source' => 'Position de Lucena',
            'active' => true,
        ];
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function createOk(array $body): array
    {
        $response = $this->api('POST', '/api/admin/evaluation/positions', $this->admin, $body);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());

        return $this->json($response);
    }

    /**
     * @return array<string, mixed>
     */
    private function check(string $fen, int $evalCp): array
    {
        $response = $this->api('POST', '/api/admin/evaluation/verification', $this->admin, ['fen' => $fen, 'evalCp' => $evalCp]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        return $this->json($response);
    }

    private function connection(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
