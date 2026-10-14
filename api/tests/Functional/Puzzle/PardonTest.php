<?php

declare(strict_types=1);

namespace App\Tests\Functional\Puzzle;

use App\Entity\User;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Response;

/**
 * The store's pardon on a rated puzzle just missed (docs/PUZZLES.md, "Erreur pardonnée"): the
 * rating comes back exactly, only on the miss sheet (the last rating change, nothing served since),
 * one per puzzle, from the stock.
 *
 * @phpstan-import-type AttemptJson from PuzzleWebTestCase
 */
final class PardonTest extends PuzzleWebTestCase
{
    public function testAPardonRestoresTheRatingExactly(): void
    {
        $alice = $this->createUser('alice@example.com');
        $this->givePardons($alice, 2);
        $this->submitOk($alice, $this->startAttempt($alice), self::playerMoves(...));
        $before = $this->ratingRow($alice);

        $missed = $this->miss($alice, $this->startAttempt($alice));
        self::assertLessThan(0, $missed['ratingDelta']);
        self::assertFalse($missed['pardoned']);

        $response = $this->pardon($alice, $missed);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $pardoned = $this->json($response);
        self::assertSame([true, 1, 'failed'], [$pardoned['pardoned'] ?? null, $pardoned['pardons'] ?? null, $pardoned['status'] ?? null]);
        self::assertSame($before, $this->ratingRow($alice), 'Rating, deviation, volatility and rated count as before the miss.');

        // The history says it: attempt, attempt, pardon.
        self::assertSame(['attempt', 'attempt', 'pardon'], $this->connection()->fetchFirstColumn(
            'SELECT reason FROM puzzle_rating_change WHERE user_id = ? ORDER BY id',
            [$alice->getId()->toBinary()],
        ));
        $read = $this->json($this->api('GET', '/api/puzzles/attempts/'.$missed['id'], $alice));
        self::assertTrue($read['pardoned'] ?? null);
        self::assertArrayHasKey('pardons', $read);
        self::assertNull($read['pardons']);

        // Once only.
        $again = $this->pardon($alice, $missed);
        self::assertSame(409, $again->getStatusCode());
        self::assertStringContainsString('not_pardonable', (string) $again->getContent());
        self::assertSame(1, $this->stock($alice));
    }

    public function testNoneLeftNothingChanges(): void
    {
        $alice = $this->createUser('alice@example.com');
        $missed = $this->miss($alice, $this->startAttempt($alice));
        $after = $this->ratingRow($alice);

        $response = $this->pardon($alice, $missed);
        self::assertSame(409, $response->getStatusCode());
        self::assertStringContainsString('no_pardon', (string) $response->getContent());
        self::assertSame($after, $this->ratingRow($alice));
        self::assertFalse($this->attemptJson($this->api('GET', '/api/puzzles/attempts/'.$missed['id'], $alice))['pardoned']);
    }

    public function testOnlyOnTheMissSheet(): void
    {
        $alice = $this->createUser('alice@example.com');
        $this->givePardons($alice, 3);

        // A solved puzzle has nothing to pardon.
        $solved = $this->submitOk($alice, $this->startAttempt($alice), self::playerMoves(...));
        self::assertStringContainsString('not_pardonable', (string) $this->pardon($alice, $solved)->getContent());

        // The next puzzle served: too late.
        $missed = $this->miss($alice, $this->startAttempt($alice));
        $this->startAttempt($alice);
        $late = $this->pardon($alice, $missed);
        self::assertSame(409, $late->getStatusCode());
        self::assertStringContainsString('too_late', (string) $late->getContent());
        self::assertSame(3, $this->stock($alice), 'Nothing spent.');
    }

    public function testAnotherUsersAttemptIsNotFound(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $this->givePardons($bob, 1);
        $missed = $this->miss($alice, $this->startAttempt($alice));

        self::assertSame(404, $this->pardon($bob, $missed)->getStatusCode());
        self::assertSame(1, $this->stock($bob));
    }

    /**
     * @param AttemptJson $attempt
     *
     * @return AttemptJson
     */
    private function miss(User $user, array $attempt): array
    {
        return $this->submitOk($user, $attempt, static fn (array $a): array => [], solutionShown: true);
    }

    /**
     * @param AttemptJson                          $attempt
     * @param callable(AttemptJson): list<string> $moves
     *
     * @return AttemptJson
     */
    private function submitOk(User $user, array $attempt, callable $moves, bool $solutionShown = false): array
    {
        $response = $this->api('POST', '/api/puzzles/attempts/'.$attempt['id'].'/submission', $user, [
            'moves' => $moves($attempt),
            'hintLevel' => 0,
            'solutionShown' => $solutionShown,
        ]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        return $this->attemptJson($response);
    }

    /**
     * @param AttemptJson $attempt
     */
    private function pardon(User $user, array $attempt): Response
    {
        return $this->api('POST', '/api/puzzles/attempts/'.$attempt['id'].'/pardon', $user);
    }

    /**
     * The rating row as stored (floats compared exactly).
     *
     * @return array<string, mixed>
     */
    private function ratingRow(User $user): array
    {
        $row = $this->connection()->fetchAssociative(
            'SELECT rating, deviation, volatility, rated_count FROM puzzle_rating WHERE user_id = ?',
            [$user->getId()->toBinary()],
        );
        self::assertIsArray($row);

        return $row;
    }

    private function givePardons(User $user, int $quantity): void
    {
        $this->connection()->executeStatement(
            "INSERT INTO shop_inventory_item (user_id, item, quantity) VALUES (?, 'puzzle_pardon', ?)",
            [$user->getId()->toBinary(), $quantity],
        );
    }

    private function stock(User $user): int
    {
        $quantity = $this->connection()->fetchOne(
            "SELECT quantity FROM shop_inventory_item WHERE user_id = ? AND item = 'puzzle_pardon'",
            [$user->getId()->toBinary()],
        );

        return is_numeric($quantity) ? (int) $quantity : 0;
    }

    private function connection(): Connection
    {
        return $this->entityManager->getConnection();
    }
}
