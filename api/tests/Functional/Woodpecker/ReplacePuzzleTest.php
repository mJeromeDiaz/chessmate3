<?php

declare(strict_types=1);

namespace App\Tests\Functional\Woodpecker;

use App\Entity\User;
use Symfony\Component\HttpFoundation\Response;

/**
 * Replacing a puzzle of a set (docs/WOODPECKER.md, "Remplacer un puzzle"): same position, same
 * profile, the pending attempt on it dropped, the history kept.
 *
 * @phpstan-type SetPuzzleJson array{puzzleId: string, position: int, rating: int, themes: list<string>, played: int, failed: int}
 */
final class ReplacePuzzleTest extends WoodpeckerWebTestCase
{
    public function testTheListComesInOrderWithItsCounts(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $set = $this->createSet($alice);
        $failed = $this->play($alice, $set['id'], []);
        $this->next($alice, $set['id']); // pending: not counted

        $list = $this->puzzlesOf($alice, $set['id']);

        self::assertSame(range(0, 4), array_column($list, 'position'));
        self::assertSame($failed['puzzle']['id'], $list[0]['puzzleId']);
        self::assertSame([1, 1], [$list[0]['played'], $list[0]['failed']]);
        self::assertSame([0, 0], [$list[1]['played'], $list[1]['failed']]);
    }

    public function testThePendingPuzzleIsSwappedWithoutAFailure(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $set = $this->createSet($alice, ['ratingMin' => 900, 'ratingMax' => 2600]);
        $before = array_column($this->puzzlesOf($alice, $set['id']), 'puzzleId');
        $pending = $this->next($alice, $set['id']);

        $new = $this->replace($alice, $set['id'], $pending['puzzle']['id']);

        self::assertSame(200, $new->getStatusCode(), (string) $new->getContent());
        /** @var SetPuzzleJson $replaced */
        $replaced = $this->json($new);
        self::assertSame(0, $replaced['position']);
        self::assertNotContains($replaced['puzzleId'], $before);
        self::assertGreaterThanOrEqual(900, $replaced['rating']);
        self::assertLessThanOrEqual(2600, $replaced['rating']);

        $after = $this->puzzlesOf($alice, $set['id']);
        self::assertSame($replaced['puzzleId'], $after[0]['puzzleId']);
        self::assertSame(\array_slice($before, 1), \array_slice(array_column($after, 'puzzleId'), 1));

        $next = $this->next($alice, $set['id']);
        self::assertNotSame($pending['id'], $next['id']);
        self::assertSame($replaced['puzzleId'], $next['puzzle']['id']);
        $current = $next['set']['current'];
        self::assertNotNull($current);
        self::assertSame([0, 0], [$current['played'], $current['failed']]);
    }

    public function testAPuzzleAlreadyPlayedInTheCycleComesBackInTheNextOne(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $set = $this->createSet($alice);
        $first = $this->play($alice, $set['id']);

        /** @var SetPuzzleJson $replaced */
        $replaced = $this->json($this->replace($alice, $set['id'], $first['puzzle']['id']));
        $this->finishRun($alice, $set['id']);

        $view = $this->getSet($alice, $set['id']);
        self::assertSame(['completed', 'active'], array_column($view['cycles'], 'status'));
        self::assertSame(5, $view['cycles'][0]['played'], 'the cycle counts the old puzzle, played before');
        self::assertSame($replaced['puzzleId'], $this->next($alice, $set['id'])['puzzle']['id']);
    }

    public function testATimedRunServesTheNewPuzzle(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $set = $this->createSet($alice);
        $run = $this->startRun($alice, $set['id']);
        $item = $this->runNext($alice, $run['id'])['item'];
        self::assertNotNull($item);

        /** @var SetPuzzleJson $replaced */
        $replaced = $this->json($this->replace($alice, $set['id'], $item['data']['puzzle']['id']));

        $served = $this->runNext($alice, $run['id'])['item'];
        self::assertNotNull($served);
        self::assertNotSame($item['id'], $served['id']);
        self::assertSame($replaced['puzzleId'], $served['data']['puzzle']['id']);
    }

    public function testStubbornPuzzlesLeaveWithTheirReplacement(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $set = $this->createSet($alice);
        $failed = $this->play($alice, $set['id'], []);
        $this->finishRun($alice, $set['id']);
        $this->play($alice, $set['id'], []);

        self::assertSame(200, $this->replace($alice, $set['id'], $failed['puzzle']['id'])->getStatusCode());

        /** @var array{member: list<mixed>} $stubborn */
        $stubborn = $this->json($this->api('GET', '/api/woodpecker/sets/'.$set['id'].'/stubborn', $alice));
        self::assertSame([], $stubborn['member']);
    }

    public function testRefusals(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $mallory = $this->createUserIn('mallory@example.com');
        $set = $this->createSet($alice);
        $puzzleId = $this->puzzlesOf($alice, $set['id'])[1]['puzzleId'];
        $outside = (string) array_values(array_diff(array_map('strval', array_keys($this->puzzles)), array_column($this->puzzlesOf($alice, $set['id']), 'puzzleId')))[0];

        self::assertSame(404, $this->replace($mallory, $set['id'], $puzzleId)->getStatusCode());
        self::assertSame(404, $this->api('GET', '/api/woodpecker/sets/'.$set['id'].'/puzzles', $mallory)->getStatusCode());
        self::assertSame(404, $this->replace($alice, $set['id'], $outside)->getStatusCode(), 'not in the set');
        self::assertSame(404, $this->replace($alice, $set['id'], 'zzzzz')->getStatusCode(), 'unknown puzzle');

        $this->api('POST', '/api/woodpecker/sets/'.$set['id'].'/pause', $alice);
        self::assertSame(200, $this->replace($alice, $set['id'], $puzzleId)->getStatusCode(), 'a paused set can be reshaped');

        $this->api('POST', '/api/woodpecker/sets/'.$set['id'].'/abandon', $alice);
        $last = $this->puzzlesOf($alice, $set['id'])[2]['puzzleId'];
        self::assertSame(409, $this->replace($alice, $set['id'], $last)->getStatusCode());
    }

    /**
     * @return list<SetPuzzleJson>
     */
    private function puzzlesOf(User $user, string $setId): array
    {
        $response = $this->api('GET', '/api/woodpecker/sets/'.$setId.'/puzzles', $user);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        /** @var array{member: list<SetPuzzleJson>} $list */
        $list = $this->json($response);

        return $list['member'];
    }

    private function replace(User $user, string $setId, string $puzzleId): Response
    {
        return $this->api('POST', '/api/woodpecker/sets/'.$setId.'/puzzles/'.$puzzleId.'/replace', $user);
    }
}
