<?php

declare(strict_types=1);

namespace App\Tests\Functional\Puzzle;

use App\Entity\Puzzle\Attempt;
use App\Entity\User;
use App\Gamification\Xp\XpRules;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PChess\Chess\Chess;
use Symfony\Component\Uid\Uuid;

/**
 * @phpstan-import-type AttemptJson from PuzzleWebTestCase
 */
final class AttemptApiTest extends PuzzleWebTestCase
{
    public function testStartHandsOutAPendingRatedAttemptWithItsSolution(): void
    {
        $user = $this->createUser('alice@example.com');

        $attempt = $this->startAttempt($user);

        self::assertSame('pending', $attempt['status']);
        self::assertTrue($attempt['rated']);
        self::assertNull($attempt['xp'], 'only a submission gains XP');
        $puzzle = $attempt['puzzle'];
        self::assertArrayHasKey($puzzle['id'], $this->puzzles);
        self::assertGreaterThanOrEqual(2, \count($puzzle['moves']));
        // The player is the side that does NOT move first in the FEN.
        self::assertSame(str_contains($puzzle['fen'], ' w ') ? 'black' : 'white', $puzzle['playerColor']);
    }

    public function testAskingAgainReturnsThePendingAttemptInsteadOfSkippingIt(): void
    {
        $user = $this->createUser('alice@example.com');

        $first = $this->startAttempt($user);
        $second = $this->startAttempt($user, ['difficulty' => 'harder']);

        self::assertSame($first['id'], $second['id']);
    }

    public function testACleanSolveRaisesTheRating(): void
    {
        $user = $this->createUser('alice@example.com');
        $attempt = $this->startAttempt($user);

        $result = $this->submit($user, $attempt, self::playerMoves($attempt));

        self::assertSame('solved', $result['status']);
        self::assertSame(0, $result['mistakes']);
        self::assertGreaterThan(0, $result['ratingDelta']);
        self::assertSame(1500.0, (float) $result['ratingBefore']);
        self::assertIsInt($result['durationMs']);
        self::assertSame(10, $result['xp'], 'XpRules: a rated puzzle solved');

        $rating = $this->json($this->api('GET', '/api/puzzles/rating', $user));
        self::assertSame(1, $rating['ratedCount']);
        self::assertGreaterThan(1500, $rating['rating']);
    }

    public function testAResultClaimedByTheClientIsIgnored(): void
    {
        $user = $this->createUser('alice@example.com');
        $attempt = $this->startAttempt($user);

        // Nothing played, but the client claims a success: the server replays and sees no solution.
        $response = $this->api('POST', $this->submissionUri($attempt), $user, [
            'moves' => [],
            'status' => 'solved',
            'success' => true,
            'ratingDelta' => 400,
        ]);

        self::assertSame(200, $response->getStatusCode());
        $result = $this->attemptJson($response);
        self::assertSame('failed', $result['status']);
        self::assertLessThan(0, $result['ratingDelta']);
    }

    public function testAWrongMoveFailsEvenIfTheSolutionIsFoundAfterwards(): void
    {
        $user = $this->createUser('alice@example.com');
        $attempt = $this->startAttempt($user);

        $result = $this->submit($user, $attempt, [self::wrongFirstMove($attempt), ...self::playerMoves($attempt)]);

        self::assertSame('failed', $result['status']);
        self::assertSame(1, $result['mistakes']);
        self::assertLessThan(0, $result['ratingDelta']);
        self::assertSame(3, $result['xp'], 'XpRules: a rated puzzle failed');
    }

    public function testTheXpShownStaysWithinTheDailyCap(): void
    {
        $user = $this->createUser('alice@example.com');
        $today = (new \DateTimeImmutable('now', $user->getDateTimeZone()))->format('Y-m-d');
        self::getContainer()->get(Connection::class)->executeStatement(
            "INSERT INTO gamification_xp_entry (id, user_id, kind, module, xp, source_type, source_id, training_run_id, local_date, occurred_at)
             VALUES (?, ?, 'exercise', 'puzzles', ?, 'test', 'earlier', NULL, ?, UTC_TIMESTAMP())",
            [Uuid::v7()->toBinary(), $user->getId()->toBinary(), XpRules::DAILY_EXERCISE_CAP - 5, $today],
            [ParameterType::BINARY, ParameterType::BINARY, ParameterType::INTEGER],
        );
        $attempt = $this->startAttempt($user);

        self::assertSame(5, $this->submit($user, $attempt, self::playerMoves($attempt))['xp'], 'what is left of the cap, not 10');
    }

    public function testAHintCountsAsAFailure(): void
    {
        $user = $this->createUser('alice@example.com');
        $attempt = $this->startAttempt($user);

        $result = $this->submit($user, $attempt, self::playerMoves($attempt), hintLevel: 1);

        self::assertSame('failed', $result['status']);
        self::assertSame(1, $result['hintLevel']);
    }

    public function testShowingTheSolutionCountsAsAFailure(): void
    {
        $user = $this->createUser('alice@example.com');
        $attempt = $this->startAttempt($user);

        $result = $this->submit($user, $attempt, [], solutionShown: true);

        self::assertSame('failed', $result['status']);
        self::assertTrue($result['solutionShown']);
    }

    public function testAnAttemptIsSubmittedOnlyOnce(): void
    {
        $user = $this->createUser('alice@example.com');
        $attempt = $this->startAttempt($user);
        $this->submit($user, $attempt, []);
        $ratingAfterFirst = $this->json($this->api('GET', '/api/puzzles/rating', $user));

        $second = $this->api('POST', $this->submissionUri($attempt), $user, ['moves' => self::playerMoves($attempt)]);

        self::assertSame(409, $second->getStatusCode());
        self::assertSame($ratingAfterFirst, $this->json($this->api('GET', '/api/puzzles/rating', $user)));
    }

    public function testAnotherUsersAttemptCanNeitherBeReadNorSubmitted(): void
    {
        $alice = $this->createUser('alice@example.com');
        $mallory = $this->createUser('mallory@example.com');
        $attempt = $this->startAttempt($alice);

        self::assertSame(404, $this->api('GET', '/api/puzzles/attempts/'.$attempt['id'], $mallory)->getStatusCode());
        self::assertSame(404, $this->api('POST', $this->submissionUri($attempt), $mallory, ['moves' => self::playerMoves($attempt)])->getStatusCode());
        self::assertSame(200, $this->api('GET', '/api/puzzles/attempts/'.$attempt['id'], $alice)->getStatusCode());

        $this->submit($alice, $attempt, []);
        self::assertSame(0, $this->historyJson($this->api('GET', '/api/puzzles/attempts', $mallory))['totalItems']);
        self::assertSame(1, $this->historyJson($this->api('GET', '/api/puzzles/attempts', $alice))['totalItems']);
    }

    public function testAnImpossibleMoveLogIsRejectedAndTheAttemptStaysPending(): void
    {
        $user = $this->createUser('alice@example.com');
        $attempt = $this->startAttempt($user);

        self::assertSame(400, $this->api('POST', $this->submissionUri($attempt), $user, ['moves' => ['a1a1']])->getStatusCode());
        self::assertSame(422, $this->api('POST', $this->submissionUri($attempt), $user, ['moves' => ['Qxf7#']])->getStatusCode());
        self::assertSame(422, $this->api('POST', $this->submissionUri($attempt), $user, ['moves' => [], 'hintLevel' => 3])->getStatusCode());

        self::assertSame('pending', $this->attemptJson($this->api('GET', '/api/puzzles/attempts/'.$attempt['id'], $user))['status']);
    }

    public function testARatedPuzzleIsNeverHandedOutAgain(): void
    {
        $user = $this->createUser('alice@example.com');

        $seen = [];
        for ($i = 0; $i < 8; ++$i) {
            $attempt = $this->startAttempt($user);
            $seen[] = $attempt['puzzle']['id'];
            $this->submit($user, $attempt, self::playerMoves($attempt));
        }

        self::assertSame($seen, array_values(array_unique($seen)));
    }

    public function testTheDatabaseRefusesASecondRatedAttemptOnTheSamePuzzle(): void
    {
        $user = $this->createUser('alice@example.com');
        $puzzle = reset($this->puzzles);
        self::assertNotFalse($puzzle);
        $this->entityManager->persist(new Attempt($user, $puzzle, true, new \DateTimeImmutable()));
        $this->entityManager->persist(new Attempt($user, $puzzle, false, new \DateTimeImmutable()));
        $this->entityManager->flush();

        $this->expectException(UniqueConstraintViolationException::class);
        $this->entityManager->persist(new Attempt($user, $puzzle, true, new \DateTimeImmutable()));
        $this->entityManager->flush();
    }

    public function testAReplayIsUnratedAndOnlyForPuzzlesFromTheHistory(): void
    {
        $user = $this->createUser('alice@example.com');
        $attempt = $this->startAttempt($user);
        $this->submit($user, $attempt, self::playerMoves($attempt));
        $ratingBefore = $this->json($this->api('GET', '/api/puzzles/rating', $user));

        $replay = $this->attemptJson($this->api('POST', '/api/puzzles/attempts', $user, ['replayOf' => $attempt['puzzle']['id']]));
        self::assertFalse($replay['rated']);
        $result = $this->submit($user, $replay, self::playerMoves($replay));

        self::assertSame('solved', $result['status']);
        self::assertNull($result['ratingDelta']);
        self::assertSame(4, $result['xp'], 'XpRules: an unrated puzzle solved');
        self::assertSame($ratingBefore, $this->json($this->api('GET', '/api/puzzles/rating', $user)));

        $unseen = array_values(array_diff(array_keys($this->puzzles), [$attempt['puzzle']['id']]))[0];
        self::assertSame(404, $this->api('POST', '/api/puzzles/attempts', $user, ['replayOf' => $unseen])->getStatusCode());

        // The pending rated puzzle is not in the history yet: no unrated side door to it.
        $pending = $this->startAttempt($user);
        self::assertSame(404, $this->api('POST', '/api/puzzles/attempts', $user, ['replayOf' => $pending['puzzle']['id']])->getStatusCode());
    }

    public function testThemeFilterAndUnknownThemes(): void
    {
        $user = $this->createUser('alice@example.com');

        $attempt = $this->startAttempt($user, ['themes' => ['mateIn1', 'promotion']]);
        self::assertNotEmpty(array_intersect(['mateIn1', 'promotion'], $attempt['puzzle']['themes']));
        $this->submit($user, $attempt, []);

        self::assertSame(422, $this->api('POST', '/api/puzzles/attempts', $user, ['themes' => ['notATheme']])->getStatusCode());
        // A real theme without any sample puzzle.
        self::assertSame(404, $this->api('POST', '/api/puzzles/attempts', $user, ['themes' => ['balestraMate']])->getStatusCode());
    }

    public function testHistoryIsPaginatedAndFiltered(): void
    {
        $user = $this->createUser('alice@example.com');
        $solved = $this->startAttempt($user);
        $this->submit($user, $solved, self::playerMoves($solved));
        $failed = $this->startAttempt($user);
        $this->submit($user, $failed, []);
        $this->startAttempt($user); // pending: not in the history

        $all = $this->historyJson($this->api('GET', '/api/puzzles/attempts', $user));
        self::assertSame(2, $all['totalItems']);
        self::assertSame($failed['id'], $all['member'][0]['id']);

        $onlySolved = $this->historyJson($this->api('GET', '/api/puzzles/attempts?result=solved', $user));
        self::assertSame(1, $onlySolved['totalItems']);
        self::assertSame($solved['id'], $onlySolved['member'][0]['id']);

        $theme = $solved['puzzle']['themes'][0];
        $byTheme = $this->historyJson($this->api('GET', '/api/puzzles/attempts?theme='.$theme, $user));
        self::assertContains($solved['id'], array_column($byTheme['member'], 'id'));
        foreach ($byTheme['member'] as $item) {
            self::assertContains($theme, $item['puzzle']['themes']);
        }

        $page = $this->historyJson($this->api('GET', '/api/puzzles/attempts?itemsPerPage=1&page=2', $user));
        self::assertCount(1, $page['member']);
        self::assertSame($solved['id'], $page['member'][0]['id']);
    }

    public function testStartingIsRateLimited(): void
    {
        $user = $this->createUser('alice@example.com');

        for ($i = 0; $i < 120; ++$i) {
            $this->api('POST', '/api/puzzles/attempts', $user, []);
        }

        self::assertSame(429, $this->api('POST', '/api/puzzles/attempts', $user, [])->getStatusCode());
    }

    public function testEndpointsRequireAuthentication(): void
    {
        $this->client->request('POST', '/api/puzzles/attempts', server: ['CONTENT_TYPE' => 'application/ld+json'], content: '{}');

        self::assertSame(401, $this->client->getResponse()->getStatusCode());
    }

    public function testConcurrentLockingKeepsOnePendingRatedAttempt(): void
    {
        $user = $this->createUser('alice@example.com');
        $this->startAttempt($user);
        $this->startAttempt($user);

        $count = self::getContainer()->get(Connection::class)->fetchOne(
            "SELECT COUNT(*) FROM puzzle_attempt WHERE user_id = ? AND status = 'pending'",
            [$user->getId()->toBinary()],
        );
        self::assertSame(1, is_numeric($count) ? (int) $count : null);
    }

    /**
     * @param AttemptJson  $attempt
     * @param list<string> $moves
     *
     * @return AttemptJson
     */
    private function submit(User $user, array $attempt, array $moves, int $hintLevel = 0, bool $solutionShown = false): array
    {
        $response = $this->api('POST', $this->submissionUri($attempt), $user, [
            'moves' => $moves,
            'hintLevel' => $hintLevel,
            'solutionShown' => $solutionShown,
        ]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        return $this->attemptJson($response);
    }

    /**
     * @param AttemptJson $attempt
     */
    private function submissionUri(array $attempt): string
    {
        return '/api/puzzles/attempts/'.$attempt['id'].'/submission';
    }

    /**
     * A legal first move that is neither the solution nor a mate.
     *
     * @param AttemptJson $attempt
     */
    private static function wrongFirstMove(array $attempt): string
    {
        $puzzle = $attempt['puzzle'];
        $chess = new Chess($puzzle['fen']);
        $opening = $puzzle['moves'][0];
        $chess->move(['from' => substr($opening, 0, 2), 'to' => substr($opening, 2, 2), 'promotion' => substr($opening, 4, 1) ?: null]);

        foreach ($chess->moves() as $move) {
            $uci = $move->from.$move->to.($move->promotion ?? '');
            $chess->move(['from' => $move->from, 'to' => $move->to, 'promotion' => $move->promotion]);
            $mates = $chess->inCheckmate();
            $chess->undo();
            if ($uci !== $puzzle['moves'][1] && !$mates) {
                return $uci;
            }
        }

        self::fail('No wrong move available.');
    }
}
