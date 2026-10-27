<?php

declare(strict_types=1);

namespace App\Tests\Functional\Arena;

use App\Activity\Event\ExerciseCompleted;
use App\Entity\User;
use App\Tests\Functional\Woodpecker\WoodpeckerWebTestCase;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

/**
 * The arena (docs/ARENA.md): the bosses met on the paths (a visualisation or calculation boss once
 * open, an endgames or défis boss once beaten), a game at a time, the moves of both sides checked
 * and timed by the server, its end (mate, resignation, time, abandonment), cancelling at any time
 * (counted nowhere) and restarting, the XP and the first defeat's crystals, the arrivals to show.
 *
 * @phpstan-type BossJson array{character: string, capacity: string, tier: string, elo: int, endgameElo: int|null, styles: list<string>, guarded: bool, status: string, crystals: int, won: int, drawn: int, lost: int, firstWonAt: string|null}
 * @phpstan-type GameJson array{id: string, boss: string, color: string, elo: int, cadence: string, incrementMs: int, moves: list<string>, playerMs: int, bossMs: int, turn: string, clocksRunning: bool, status: string, end: string|null, crystals: int, xp: int}
 */
final class ArenaTest extends WoodpeckerWebTestCase
{
    private const SCHOLAR = ['e2e4', 'e7e5', 'f1c4', 'b8c6', 'd1h5', 'g8f6', 'h5f7'];

    public function testTheBossesOpenAsTheyAreMetOnThePaths(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $arena = $this->overview($alice);
        $statuses = array_column($arena['bosses'], 'status', 'character');
        self::assertSame('available', $statuses['jongleur'], 'the first chapter\'s boss is open on its path');
        self::assertSame('available', $statuses['metronome']);
        self::assertSame('locked', $statuses['cyclope']);
        self::assertSame('locked', $statuses['bourreau'], 'an endgames boss: once beaten');
        self::assertSame('locked', $statuses['defi-mates']);
        self::assertSame('locked', $statuses['defi-opening']);
        self::assertSame('soon', $statuses['sith'], 'its fight is not built yet');
        self::assertSame(['3+2', '5+3', '10+5', '15+10'], $arena['cadences']);
        self::assertNull($arena['currentGame']);
        $jongleur = $this->boss($arena, 'jongleur');
        self::assertSame(['visualisation', 'chapter', 1000, null, 100, 0], [$jongleur['capacity'], $jongleur['tier'], $jongleur['elo'], $jongleur['endgameElo'], $jongleur['crystals'], $jongleur['won']]);

        $this->completed($alice, 'endgames', 'mates-boss');
        $this->completed($alice, 'challenges', 'op-'.strtolower(Uuid::v7()->toBase32()).'-boss');
        $this->completed($alice, 'visualisation', '1-boss');
        $statuses = array_column($this->overview($alice)['bosses'], 'status', 'character');
        self::assertSame(['available', 'available', 'available', 'locked'], [$statuses['bourreau'], $statuses['defi-opening'], $statuses['cyclope'], $statuses['gardien']]);
        self::assertSame(1700, $this->boss($this->overview($alice), 'bourreau')['endgameElo']);
    }

    public function testAGameIsPlayedByBothSidesMovesAndPaysTheFirstDefeatOnce(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $this->assertRefused($this->api('POST', '/api/arena/games', $alice, ['boss' => 'bourreau']), 409, 'arena_boss_locked');
        $this->assertRefused($this->api('POST', '/api/arena/games', $alice, ['boss' => 'dragon']), 404, 'arena_unknown_boss');
        self::assertSame(422, $this->api('POST', '/api/arena/games', $alice, ['boss' => 'jongleur', 'cadence' => '1+0'])->getStatusCode());

        $game = $this->start($alice, 'jongleur', 'white', '3+2');
        self::assertSame(['jongleur', 'white', 1000, '3+2', 2000, [], 180_000, 180_000, 'player', false, 'active'], [
            $game['boss'], $game['color'], $game['elo'], $game['cadence'], $game['incrementMs'], $game['moves'],
            $game['playerMs'], $game['bossMs'], $game['turn'], $game['clocksRunning'], $game['status'],
        ]);
        $this->assertRefused($this->api('POST', '/api/arena/games', $alice, ['boss' => 'metronome']), 409, 'arena_game_in_progress');
        self::assertSame($game['id'], $this->overview($alice)['currentGame']);
        self::assertSame($game['id'], $this->gameJson($this->api('GET', '/api/arena/games/current', $alice))['id'], '/current is not taken for an id');

        $this->assertRefused($this->move($alice, $game['id'], 0, 'e2e5'), 422, 'arena_illegal_move');
        $this->assertRefused($this->move($alice, $game['id'], 1, 'e2e4'), 409, 'arena_out_of_sync');
        $this->playMoves($alice, $game['id'], ['e2e4']);
        self::assertSame(['e2e4'], $this->gameJson($this->move($alice, $game['id'], 0, 'e2e4'))['moves'], 'a retry answers the game as it stands');
        $this->travel('+5 seconds');
        $after = $this->playMoves($alice, $game['id'], ['e7e5']);
        self::assertSame(['player', true], [$after['turn'], $after['clocksRunning']]);
        $this->travel('+10 seconds');
        self::assertSame(170_000, $this->game($alice, $game['id'])['playerMs'], 'the player\'s clock runs down');

        $won = $this->playMoves($alice, $game['id'], \array_slice(self::SCHOLAR, 2), 2);
        self::assertSame(['won', 'checkmate', 100, 30], [$won['status'], $won['end'], $won['crystals'], $won['xp']]);
        self::assertSame(100, $this->balance($alice));
        self::assertSame(1, $this->outboxCount('arena_game'));
        $this->assertRefused($this->move($alice, $won['id'], 7, 'e8e7'), 409, 'arena_game_over');

        $again = $this->start($alice, 'jongleur', 'white', '5+3');
        self::assertSame([0, 30], [$this->playMoves($alice, $again['id'], self::SCHOLAR)['crystals'], $this->game($alice, $again['id'])['xp']], 'paid once');
        self::assertSame(100, $this->balance($alice));
        $jongleur = $this->boss($this->overview($alice), 'jongleur');
        self::assertSame([2, 0, 0, 0], [$jongleur['won'], $jongleur['drawn'], $jongleur['lost'], $jongleur['crystals']]);
        self::assertSame(['arena_first'], array_column($this->overview($alice)['badges'], 'badge'));
    }

    public function testTheBossMatesResignationAndAnotherPlayersGame(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $bob = $this->createUserIn('bob@example.com');
        $game = $this->start($alice, 'metronome', 'white', '5+3');
        $this->assertRefused($this->api('GET', '/api/arena/games/'.$game['id'], $bob), 404, 'arena_game_not_found');
        $this->assertRefused($this->move($bob, $game['id'], 0, 'e2e4'), 404, 'arena_game_not_found');
        // The fool's mate: the boss mates, a short game gains nothing.
        $lost = $this->playMoves($alice, $game['id'], ['f2f3', 'e7e5', 'g2g4', 'd8h4']);
        self::assertSame(['lost', 'checkmate', 0, 0], [$lost['status'], $lost['end'], $lost['crystals'], $lost['xp']]);

        $black = $this->start($alice, 'metronome', 'black', '5+3');
        self::assertSame('boss', $black['turn'], 'the boss has White');
        $resigned = $this->gameJson($this->api('POST', '/api/arena/games/'.$black['id'].'/resign', $alice));
        self::assertSame(['lost', 'resignation'], [$resigned['status'], $resigned['end']]);
        self::assertSame([0, 0, 2], array_values(array_intersect_key($this->boss($this->overview($alice), 'metronome'), ['won' => 1, 'drawn' => 1, 'lost' => 1])));
    }

    public function testThePlayersClockAndAnAbandonmentEndTheGameByThemselves(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $game = $this->start($alice, 'jongleur', 'white', '3+2');
        $this->playMoves($alice, $game['id'], ['e2e4', 'e7e5']);
        $this->travel('+181 seconds');
        self::assertSame('active', $this->game($alice, $game['id'])['status'], 'within the tolerance');
        $this->travel('+2 seconds');
        $this->assertRefused($this->api('GET', '/api/arena/games/current', $alice), 404, 'arena_game_not_found');
        $timeout = $this->game($alice, $game['id']);
        self::assertSame(['lost', 'timeout', 0], [$timeout['status'], $timeout['end'], $timeout['playerMs']]);

        $left = $this->start($alice, 'jongleur', 'white', '3+2');
        $this->playMoves($alice, $left['id'], ['e2e4']);
        $this->travel('+301 seconds');
        self::assertNull($this->overview($alice)['currentGame']);
        self::assertSame(['lost', 'abandonment'], [$this->game($alice, $left['id'])['status'], $this->game($alice, $left['id'])['end']]);
    }

    public function testCancellingCountsNowhereAndRestartingStartsTheSameGameAgain(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $game = $this->start($alice, 'jongleur', 'black', '10+5');
        $this->playMoves($alice, $game['id'], ['e2e4', 'e7e5', 'g1f3']);
        $restarted = $this->gameJson($this->api('POST', '/api/arena/games/'.$game['id'].'/restart', $alice), 201);
        self::assertNotSame($game['id'], $restarted['id']);
        self::assertSame(['jongleur', 'black', '10+5', [], 'active'], [$restarted['boss'], $restarted['color'], $restarted['cadence'], $restarted['moves'], $restarted['status']]);
        self::assertSame(['aborted', 'aborted', 0], [$this->game($alice, $game['id'])['status'], $this->game($alice, $game['id'])['end'], $this->game($alice, $game['id'])['xp']]);

        $aborted = $this->gameJson($this->api('POST', '/api/arena/games/'.$restarted['id'].'/abort', $alice));
        self::assertSame('aborted', $aborted['status']);
        $this->assertRefused($this->api('POST', '/api/arena/games/'.$restarted['id'].'/abort', $alice), 409, 'arena_game_over');
        $jongleur = $this->boss($this->overview($alice), 'jongleur');
        self::assertSame([0, 0, 0], [$jongleur['won'], $jongleur['drawn'], $jongleur['lost']], 'cancelled games count nowhere');
        self::assertSame(0, $this->outboxCount('arena_game'), 'nor in the activity log');
    }

    public function testTheBossesArrivalsWaitToBeShown(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        self::assertSame(['jongleur', 'metronome'], $this->pending($alice), 'at the arena\'s opening: every boss already met, shown together');
        self::assertSame(['jongleur', 'metronome'], $this->overview($alice)['pendingUnlocks']);
        $after = $this->json($this->api('POST', '/api/arena/unlocks/announce', $alice, ['bosses' => ['jongleur']]));
        self::assertSame(['metronome'], array_column(\is_array($after['bosses'] ?? null) ? $after['bosses'] : [], 'character'));
        $this->api('POST', '/api/arena/unlocks/announce', $alice, ['bosses' => ['metronome', 'jongleur']]);
        self::assertSame([], $this->pending($alice));

        // The Bourreau beaten on the endgames path: it arrives.
        $this->completed($alice, 'endgames', 'mates-boss');
        self::assertSame(['bourreau'], $this->pending($alice));
        self::assertSame(422, $this->api('POST', '/api/arena/unlocks/announce', $alice, ['bosses' => []])->getStatusCode());
    }

    /**
     * @return array{bosses: list<BossJson>, cadences: list<string>, defaultCadence: string, badges: list<array{badge: string, earnedAt: string}>, currentGame: string|null, pendingUnlocks: list<string>}
     */
    private function overview(User $user): array
    {
        $response = $this->api('GET', '/api/arena', $user);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        /** @var array{bosses: list<BossJson>, cadences: list<string>, defaultCadence: string, badges: list<array{badge: string, earnedAt: string}>, currentGame: string|null, pendingUnlocks: list<string>} */
        return $this->json($response);
    }

    /**
     * @param array{bosses: list<BossJson>} $arena
     *
     * @return BossJson
     */
    private function boss(array $arena, string $character): array
    {
        foreach ($arena['bosses'] as $boss) {
            if ($boss['character'] === $character) {
                return $boss;
            }
        }
        self::fail($character.' is not in the arena');
    }

    /**
     * @return list<string>
     */
    private function pending(User $user): array
    {
        $response = $this->api('GET', '/api/arena/unlocks', $user);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $bosses = $this->json($response)['bosses'] ?? [];

        return array_values(array_map(static fn (mixed $boss): string => \is_array($boss) && \is_string($boss['character'] ?? null) ? $boss['character'] : '', \is_array($bosses) ? $bosses : []));
    }

    /**
     * @return GameJson
     */
    private function start(User $user, string $boss, string $color, string $cadence): array
    {
        return $this->gameJson($this->api('POST', '/api/arena/games', $user, ['boss' => $boss, 'color' => $color, 'cadence' => $cadence]), 201);
    }

    /**
     * @return GameJson
     */
    private function game(User $user, string $id): array
    {
        return $this->gameJson($this->api('GET', '/api/arena/games/'.$id, $user));
    }

    private function move(User $user, string $id, int $ply, string $move): Response
    {
        return $this->api('POST', '/api/arena/games/'.$id.'/moves', $user, ['ply' => $ply, 'move' => $move]);
    }

    /**
     * Plays the moves in turn, both sides', from move number $from.
     *
     * @param list<string> $moves
     *
     * @return GameJson the game after the last
     */
    private function playMoves(User $user, string $id, array $moves, int $from = -1): array
    {
        $ply = $from < 0 ? \count($this->game($user, $id)['moves']) : $from;
        $game = null;
        foreach ($moves as $move) {
            $game = $this->gameJson($this->move($user, $id, $ply++, $move));
        }
        self::assertNotNull($game);

        return $game;
    }

    /**
     * @return GameJson
     */
    private function gameJson(Response $response, int $status = 200): array
    {
        self::assertSame($status, $response->getStatusCode(), (string) $response->getContent());

        /** @var GameJson */
        return $this->json($response);
    }

    private function assertRefused(Response $response, int $status, string $reason): void
    {
        self::assertSame($status, $response->getStatusCode(), (string) $response->getContent());
        self::assertStringContainsString($reason, (string) $response->getContent());
    }

    /** A step of a path set as completed. */
    private function completed(User $user, string $capacity, string $step): void
    {
        $this->connection()->executeStatement(
            "INSERT INTO mind_map_progress (id, user_id, capacity, step, passes, completed_at, updated_at) VALUES (?, ?, ?, ?, 1, '2026-09-28 09:00:00', '2026-09-28 09:00:00')",
            [Uuid::v7()->toBinary(), $user->getId()->toBinary(), $capacity, $step],
        );
    }

    private function balance(User $user): int
    {
        $balance = $this->connection()->fetchOne('SELECT balance FROM shop_wallet WHERE user_id = ?', [$user->getId()->toBinary()]);

        return is_numeric($balance) ? (int) $balance : 0;
    }

    /** The exercises of this source waiting in the activity outbox. */
    private function outboxCount(string $sourceType): int
    {
        $count = 0;
        foreach ($this->takeOutboxMessages() as $message) {
            if ($message instanceof ExerciseCompleted && $sourceType === $message->sourceType) {
                ++$count;
            }
        }

        return $count;
    }

    private function connection(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
