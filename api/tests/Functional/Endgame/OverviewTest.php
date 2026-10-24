<?php

declare(strict_types=1);

namespace App\Tests\Functional\Endgame;

use App\Chess\Position\FenNormalizer;
use App\Endgame\Lesson\Catalogue;
use App\Entity\Puzzle\Rating;
use App\Entity\User;
use App\Gamification\Xp\XpRules;
use App\Tests\Functional\Woodpecker\WoodpeckerWebTestCase;
use Doctrine\DBAL\ParameterType;

/**
 * GET /endgames (docs/ENDGAMES.md, § 7): the rules, the lessons in the catalogue's order with their
 * variants and the user's own results, the Elo proposed from the puzzle rating.
 */
final class OverviewTest extends WoodpeckerWebTestCase
{
    use KeptAnswersTrait;

    private const START = '8/8/8/P7/8/8/5k2/7K w - - 0 1';

    public function testLessonsWithTheirVariantsAndTheUsersResults(): void
    {
        self::keep(self::START, self::winWith('a5a6', 'h1h2', 23));
        self::keep('8/8/P7/8/8/4k3/8/7K w - - 0 1', self::winWith('a6a7'));
        self::keep('8/P7/8/8/3k4/8/8/7K w - - 0 1', self::winWith('a7a8q'));
        $alice = $this->createUserIn('alice@example.com');
        $bob = $this->createUserIn('bob@example.com');

        // Alice wins the textbook position, then spoils the next one.
        $run = $this->postOk($alice, '/api/training/runs', ['module' => 'endgames', 'subjectId' => $alice->getId()->toRfc4122(), 'budgetSeconds' => 300, 'config' => ['mode' => 'learn', 'lessons' => ['square-promote'], 'elo' => 1500]], 201)['id'];
        self::assertIsString($run);
        foreach ([['a5a6', 'f2e3', 'a6a7', 'e3d4', 'a7a8q'], ['h1h2']] as $moves) {
            $item = $this->postOk($alice, '/api/training/runs/'.$run.'/next', null, 200)['item'];
            self::assertIsArray($item);
            $this->postOk($alice, '/api/training/runs/'.$run.'/submission', ['itemId' => $item['id'], 'moves' => $moves], 200);
        }

        // Added after the run, which would otherwise serve this variant second (learn mode).
        $this->addToPool('square-promote', '8/8/8/7P/8/8/2k5/K7 w - - 0 1', false);
        $this->addToPool('square-promote', self::START, true);

        $view = $this->overview($alice);
        self::assertSame([2, 5, 10, 15], $view['rules']['runMinutes']);
        self::assertSame([800, 2600, 100], [$view['rules']['minElo'], $view['rules']['maxElo'], $view['rules']['eloStep']]);
        self::assertSame(XpRules::ENDGAME_PERFECT, $view['rules']['xp']['perfect']);
        self::assertSame(1500, $view['elo'], 'No puzzle rating yet: the default one.');
        self::assertSame(array_keys(Catalogue::all()), array_column($view['lessons'], 'key'));

        $lessons = array_column($view['lessons'], null, 'key');
        $square = $lessons['square-promote'];
        self::assertSame(['pawns', 'promote', 'win', null, 1, 2, 1], [$square['family'], $square['goal'], $square['result'], $square['holdMoves'], $square['variants'], $square['played'], $square['succeeded']]);
        self::assertNotNull($square['lastPlayedAt']);
        self::assertSame(Catalogue::get('square-promote')?->textbook, $square['textbook'], 'Its textbook position, for the demonstration.');
        self::assertSame(['hold', 'draw', 8, 0, 0, null], [$lessons['square-stop']['goal'], $lessons['square-stop']['result'], $lessons['square-stop']['holdMoves'], $lessons['square-stop']['variants'], $lessons['square-stop']['played'], $lessons['square-stop']['lastPlayedAt']]);

        $other = array_column($this->overview($bob)['lessons'], null, 'key')['square-promote'];
        self::assertSame([1, 0, 0], [$other['variants'], $other['played'], $other['succeeded']], "Bob does not see Alice's results.");
    }

    public function testTheEloProposedFollowsThePuzzleRating(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $this->entityManager->persist(new Rating($alice));
        $this->entityManager->flush();
        $this->entityManager->getConnection()->executeStatement('UPDATE puzzle_rating SET rating = 1652.4 WHERE user_id = ?', [$alice->getId()->toBinary()], [ParameterType::BINARY]);
        // The raw UPDATE bypassed the identity map, which the request shares: reload.
        $this->entityManager->clear();

        self::assertSame(1700, $this->overview($alice)['elo']);
    }

    public function testSignedInOnlyAndTheTablebaseRouteIsNotCaptured(): void
    {
        $this->client->request('GET', '/api/endgames', server: ['HTTP_ACCEPT' => 'application/ld+json']);
        self::assertSame(401, $this->client->getResponse()->getStatusCode());

        $alice = $this->createUserIn('alice@example.com');
        self::keep(self::START, self::winWith('a5a6'));
        $response = $this->api('GET', '/api/endgames/tablebase?fen='.rawurlencode(self::START), $alice);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertArrayHasKey('defence', $this->json($response));
    }

    /**
     * @return array{rules: array{runMinutes: list<int>, minElo: int, maxElo: int, eloStep: int, xp: array<string, int>}, elo: int, lessons: list<array<string, mixed>>}
     */
    private function overview(User $user): array
    {
        $response = $this->api('GET', '/api/endgames', $user);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        /** @var array{rules: array{runMinutes: list<int>, minElo: int, maxElo: int, eloStep: int, xp: array<string, int>}, elo: int, lessons: list<array<string, mixed>>} */
        return $this->json($response);
    }

    /**
     * @param array<string, mixed>|null $body
     *
     * @return array<string, mixed>
     */
    private function postOk(User $user, string $uri, ?array $body, ?int $status): array
    {
        $response = $this->api('POST', $uri, $user, $body);
        if (null !== $status) {
            self::assertSame($status, $response->getStatusCode(), (string) $response->getContent());
        }

        return $this->json($response);
    }

    private function addToPool(string $lesson, string $fen, bool $textbook): void
    {
        self::getContainer()->get('doctrine.dbal.endgame_connection')->executeStatement(
            'INSERT INTO endgame_position (lesson_key, fen, fen_hash, dtm, textbook, random_key, created_at) VALUES (?, ?, ?, 31, ?, 5, NOW())',
            [$lesson, $fen, (new FenNormalizer())->key($fen)->hash, $textbook ? 1 : 0],
            [ParameterType::STRING, ParameterType::STRING, ParameterType::BINARY, ParameterType::INTEGER],
        );
    }
}
