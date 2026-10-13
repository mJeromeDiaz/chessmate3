<?php

declare(strict_types=1);

namespace App\Tests\Functional\Onboarding;

use App\Entity\AuthIdentity;
use App\Entity\User;
use App\Enum\AuthProvider;
use App\Tests\Functional\Puzzle\PuzzleWebTestCase;
use Doctrine\DBAL\Connection;

/**
 * The guided tour's answers (docs/ONBOARDING.md): the declared level seeds the puzzle rating until
 * a rated puzzle is played or the Lichess puzzle rating is imported; the goal is only kept.
 */
final class OnboardingTest extends PuzzleWebTestCase
{
    public function testANewPlayerHasNoAnswersYet(): void
    {
        $alice = $this->createUser('alice@example.com');
        $response = $this->api('GET', '/api/onboarding', $alice);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame([
            'level' => null,
            'goal' => null,
            'suggestedLevel' => null,
            'lichess' => ['linked' => false, 'perf' => null, 'rating' => null],
            'canSeedRating' => true,
            'puzzleRating' => null,
        ], array_intersect_key($this->json($response), array_flip(['level', 'goal', 'suggestedLevel', 'lichess', 'canSeedRating', 'puzzleRating'])));
    }

    public function testTheDeclaredLevelSeedsThePuzzleRatingUntilARatedPuzzle(): void
    {
        $alice = $this->createUser('alice@example.com');

        $saved = $this->put($alice, ['level' => 'expert', 'goal' => 'kasparov']);
        self::assertSame(['expert', 'kasparov', 2200, true], [$saved['level'], $saved['goal'], $saved['puzzleRating'], $saved['canSeedRating']]);
        $rating = $this->json($this->api('GET', '/api/puzzles/rating', $alice));
        self::assertSame([2200, 250, 'declared'], [$rating['rating'], $rating['deviation'], $rating['source']]);

        // Declared again: the new level replaces it, each change in the history.
        self::assertSame(1400, $this->put($alice, ['level' => 'amateur', 'goal' => 'fun'])['puzzleRating']);
        self::assertSame(['level_declared', 'level_declared'], $this->reasons($alice));
        // The same level again: nothing more in the history.
        $this->put($alice, ['level' => 'amateur', 'goal' => 'rating']);
        self::assertCount(2, $this->reasons($alice));

        // A rated puzzle: the rating is the player's now.
        $attempt = $this->startAttempt($alice);
        $this->api('POST', '/api/puzzles/attempts/'.$attempt['id'].'/submission', $alice, ['moves' => []]);
        $played = $this->json($this->api('GET', '/api/puzzles/rating', $alice))['rating'];
        $after = $this->put($alice, ['level' => 'pro', 'goal' => 'blunders']);
        self::assertSame(['pro', 'blunders', false], [$after['level'], $after['goal'], $after['canSeedRating']]);
        self::assertSame($played, $this->json($this->api('GET', '/api/puzzles/rating', $alice))['rating']);
    }

    public function testNoLevelKeepsTheDefaultRating(): void
    {
        $alice = $this->createUser('alice@example.com');
        $saved = $this->put($alice, ['level' => null, 'goal' => 'understanding']);

        self::assertSame([null, 'understanding', null], [$saved['level'], $saved['goal'], $saved['puzzleRating']]);
        self::assertSame([], $this->reasons($alice));
    }

    public function testAnImportedLichessRatingIsNeverReplaced(): void
    {
        $alice = $this->createLichessUser(['rapid' => ['rating' => 2134, 'provisional' => false]]);
        $this->connection()->executeStatement(
            "INSERT INTO puzzle_rating (user_id, rating, deviation, volatility, rated_count, source, updated_at) VALUES (?, 2034, 150, 0.06, 0, 'lichess', UTC_TIMESTAMP())",
            [$alice->getId()->toBinary()],
        );

        $view = $this->json($this->api('GET', '/api/onboarding', $alice));
        self::assertSame(['linked' => true, 'perf' => 'rapid', 'rating' => 2134], $view['lichess']);
        self::assertSame(['expert', false, 2034], [$view['suggestedLevel'], $view['canSeedRating'], $view['puzzleRating']]);

        self::assertSame(2034, $this->put($alice, ['level' => 'beginner', 'goal' => null])['puzzleRating']);
    }

    public function testTheLichessImportStaysOfferedOverADeclaredLevel(): void
    {
        $alice = $this->createLichessUser([]);
        $this->put($alice, ['level' => 'confirmed', 'goal' => null]);

        self::assertTrue($this->json($this->api('GET', '/api/puzzles/rating', $alice))['lichessImportAvailable']);
        self::assertNull($this->json($this->api('GET', '/api/onboarding', $alice))['suggestedLevel'], 'no rating in the Lichess snapshot');
    }

    public function testAnswersAreChecked(): void
    {
        $alice = $this->createUser('alice@example.com');

        self::assertSame(422, $this->api('PUT', '/api/onboarding', $alice, ['level' => 'grandmaster', 'goal' => null])->getStatusCode());
        self::assertSame(422, $this->api('PUT', '/api/onboarding', $alice, ['level' => null, 'goal' => 'world_champion'])->getStatusCode());
    }

    public function testTheAnswersAreInTheDataExport(): void
    {
        $alice = $this->createUser('alice@example.com');
        $this->put($alice, ['level' => 'amateur', 'goal' => 'fun']);

        $this->client->request('GET', '/api/profile/export', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->tokenFor($alice)]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        // The test client captured the sent file (deleted after sending).
        $path = (string) tempnam(sys_get_temp_dir(), 'export-test-');
        file_put_contents($path, $this->client->getInternalResponse()->getContent());
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path));
        /** @var array{onboarding: array{level: string|null, goal: string|null}} $profile */
        $profile = json_decode((string) $zip->getFromName('profil.json'), true, flags: \JSON_THROW_ON_ERROR);
        $zip->close();
        unlink($path);

        self::assertSame(['amateur', 'fun'], [$profile['onboarding']['level'], $profile['onboarding']['goal']]);
    }

    /**
     * @param array{level: string|null, goal: string|null} $answers
     *
     * @return array<string, mixed>
     */
    private function put(User $user, array $answers): array
    {
        $response = $this->api('PUT', '/api/onboarding', $user, $answers);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        return $this->json($response);
    }

    /**
     * @return list<string> the puzzle rating history's reasons, oldest first
     */
    private function reasons(User $user): array
    {
        return array_values(array_filter($this->connection()->fetchFirstColumn(
            'SELECT reason FROM puzzle_rating_change WHERE user_id = ? ORDER BY created_at, id',
            [$user->getId()->toBinary()],
        ), 'is_string'));
    }

    /**
     * @param array<string, mixed> $ratings the identity's rating snapshot
     */
    private function createLichessUser(array $ratings): User
    {
        $user = $this->createUser('alice@example.com');
        $identity = new AuthIdentity($user, AuthProvider::Lichess, 'alicelichess');
        $identity->setMetadata(['ratings' => $ratings]);
        $this->entityManager->persist($identity);
        $this->entityManager->flush();

        return $user;
    }

    private function connection(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
