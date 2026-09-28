<?php

declare(strict_types=1);

namespace App\Tests\Functional\Puzzle;

use App\Entity\AuthIdentity;
use App\Entity\User;
use App\Enum\AuthProvider;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;

final class LichessImportTest extends PuzzleWebTestCase
{
    /** @var list<string> */
    private array $requestedUrls = [];

    public function testTheLichessPuzzleRatingSeedsARatingWithAWideDeviation(): void
    {
        $this->mockLichess(['perfs' => ['puzzle' => ['games' => 812, 'rating' => 2034, 'rd' => 62, 'prog' => 12]]]);
        $user = $this->createLichessUser();

        self::assertTrue($this->json($this->api('GET', '/api/puzzles/rating', $user))['lichessImportAvailable']);
        $response = $this->api('POST', '/api/puzzles/rating/lichess-import', $user);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $rating = $this->json($response);
        self::assertSame(2034, $rating['rating']);
        self::assertSame(150, $rating['deviation']);
        self::assertSame('lichess', $rating['source']);
        self::assertFalse($rating['lichessImportAvailable']);
        self::assertSame(['https://lichess.org/api/user/alicelichess'], $this->requestedUrls);

        // Once only.
        self::assertSame(409, $this->api('POST', '/api/puzzles/rating/lichess-import', $user)->getStatusCode());
    }

    public function testNoImportAfterARatedPuzzle(): void
    {
        $this->mockLichess(['perfs' => ['puzzle' => ['games' => 5, 'rating' => 1800, 'rd' => 200]]]);
        $user = $this->createLichessUser();
        $attempt = $this->startAttempt($user);
        $this->api('POST', '/api/puzzles/attempts/'.$attempt['id'].'/submission', $user, ['moves' => []]);

        self::assertSame(409, $this->api('POST', '/api/puzzles/rating/lichess-import', $user)->getStatusCode());
        self::assertSame([], $this->requestedUrls, 'Lichess is not called when the import is refused anyway');
    }

    public function testAnAccountWithoutPuzzlesOrWithoutLichessIsRefused(): void
    {
        $this->mockLichess(['perfs' => ['blitz' => ['games' => 10, 'rating' => 1700, 'rd' => 80]]]);

        self::assertSame(422, $this->api('POST', '/api/puzzles/rating/lichess-import', $this->createLichessUser())->getStatusCode());
        self::assertSame(422, $this->api('POST', '/api/puzzles/rating/lichess-import', $this->createUser('bob@example.com'))->getStatusCode());
    }

    /**
     * @param array<string, mixed> $body
     */
    private function mockLichess(array $body): void
    {
        // A reboot between requests would rebuild the container and drop the mock.
        $this->client->disableReboot();
        self::getContainer()->set('lichess_api.client', new MockHttpClient(function (string $method, string $url) use ($body): JsonMockResponse {
            $this->requestedUrls[] = $url;

            return new JsonMockResponse($body);
        }, 'https://lichess.org'));
    }

    private function createLichessUser(): User
    {
        $user = $this->createUser('alice@example.com');
        $this->entityManager->persist(new AuthIdentity($user, AuthProvider::Lichess, 'alicelichess'));
        $this->entityManager->flush();

        return $user;
    }
}
