<?php

declare(strict_types=1);

namespace App\Tests\Functional\Guest;

use App\Entity\User;
use App\Tests\Functional\Woodpecker\WoodpeckerWebTestCase;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

/**
 * Guests (docs/GUEST.md): « Essayer sans compte » opens an account and its session, capped per IP;
 * the API is closed to it but a whitelist; the paths' first chapter only; no crystal credited, what
 * it missed told; XP kept for its promotion; the unreachable guests purged; the admin's figures
 * without them.
 *
 * @phpstan-type OutcomeJson array{id: string, outcome: string, completed: bool, xp: int, crystals: int, chest: array{id: string, step: string, crystals: int, opened: bool}|null, unopenedChests: list<array{id: string}>}
 */
final class GuestTest extends WoodpeckerWebTestCase
{
    private const SCHOLAR = ['e2e4', 'e7e5', 'f1c4', 'b8c6', 'd1h5', 'g8f6', 'h5f7'];

    public function testAGuestIsOpenedWithItsSession(): void
    {
        $this->client->request('POST', '/api/auth/guest', server: ['HTTP_X-Guest-Request' => '1']);
        $response = $this->client->getResponse();

        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());
        $token = $this->json($response)['accessToken'] ?? null;
        self::assertIsString($token);
        self::assertNotEmpty(array_filter($response->headers->getCookies(), static fn (Cookie $cookie): bool => 'refresh_token' === $cookie->getName()), 'A refresh cookie, as a sign-in.');

        $this->client->request('GET', '/api/profile', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token, 'HTTP_ACCEPT' => 'application/json']);
        $profile = $this->json($this->client->getResponse());
        self::assertSame([true, false, null], [$profile['isGuest'] ?? null, $profile['isAdmin'] ?? null, $profile['email'] ?? null]);

        $guests = $this->connection()->fetchOne('SELECT COUNT(*) FROM app_user WHERE guest = 1');
        self::assertSame(1, is_numeric($guests) ? (int) $guests : null);
    }

    public function testGuestsAreCappedPerIp(): void
    {
        $this->client->request('POST', '/api/auth/guest');
        self::assertSame(403, $this->client->getResponse()->getStatusCode(), 'Without its header: a cross-site form.');

        for ($i = 0; $i < 5; ++$i) {
            $this->client->request('POST', '/api/auth/guest', server: ['HTTP_X-Guest-Request' => '1']);
            self::assertSame(201, $this->client->getResponse()->getStatusCode());
        }
        $this->client->request('POST', '/api/auth/guest', server: ['HTTP_X-Guest-Request' => '1']);
        self::assertSame(429, $this->client->getResponse()->getStatusCode());
    }

    public function testTheApiIsClosedToAGuestButAWhitelist(): void
    {
        $guest = $this->createGuest();

        foreach ([
            ['GET', '/api/repertoires'],
            ['GET', '/api/training/sessions'],
            ['GET', '/api/training/plans'],
            ['GET', '/api/shop'],
            ['GET', '/api/gamification/summary'],
            ['GET', '/api/announcements'],
            ['GET', '/api/onboarding'],
            ['GET', '/api/coach/builds/'.Uuid::v7()->toRfc4122().'/diagnosis'],
            ['PUT', '/api/profile/info'],
            ['GET', '/api/profile/export'],
            ['POST', '/api/arena/bosses/requin/purchase'],
            ['POST', '/api/puzzles/rating/lichess-import'],
        ] as [$method, $uri]) {
            self::assertSame(403, $this->api($method, $uri, $guest, 'GET' === $method ? null : [])->getStatusCode(), $method.' '.$uri);
        }
        foreach (['/api/profile', '/api/arena', '/api/mind-map/visualisation', '/api/puzzle-rush', '/api/dashboard/activity'] as $uri) {
            self::assertNotContains($this->api('GET', $uri, $guest)->getStatusCode(), [401, 403], $uri);
        }

        // A module of an account's, on its own: refused before anything is checked.
        $this->assertRefused($this->api('POST', '/api/training/runs', $guest, ['module' => 'free', 'subjectId' => $guest->getId()->toRfc4122(), 'budgetSeconds' => 600]), 403, 'account_required');
    }

    public function testAGuestPlaysThePathsFirstChapterOnly(): void
    {
        $guest = $this->createGuest();

        $this->assertRefused($this->api('POST', '/api/mind-map/visualisation/steps/2-1-1/start', $guest), 403, 'account_required');
        $this->assertRefused($this->api('POST', '/api/mind-map/visualisation/steps/2-1-1/skip', $guest), 403, 'account_required');
        self::assertSame(200, $this->api('POST', '/api/mind-map/visualisation/steps/1-1/skip', $guest)->getStatusCode());
        self::assertSame(201, $this->api('POST', '/api/mind-map/visualisation/steps/1-2/start', $guest)->getStatusCode());
    }

    public function testAGuestEarnsNoCrystalButIsToldWhatItMissedAndKeepsItsXp(): void
    {
        $guest = $this->createGuest();
        foreach (['1-2', '1-3'] as $step) {
            $this->completed($guest, $step);
        }

        $this->playExercise($guest);
        $outcome = $this->playExercise($guest);

        self::assertTrue($outcome['completed']);
        self::assertSame([60, []], [$outcome['crystals'], $outcome['unopenedChests']], 'The chest on the way: nothing to open, its crystals missed.');
        self::assertSame(0, $this->balanceOf($guest));
        $this->runOutbox();
        self::assertGreaterThan(0, $this->xpOf($guest), 'Its XP is kept for its promotion.');
        $chests = $this->connection()->fetchOne('SELECT COUNT(*) FROM shop_daily_reward WHERE user_id = ?', [$guest->getId()->toBinary()]);
        self::assertSame(0, is_numeric($chests) ? (int) $chests : null, 'No streak, no daily chest.');

        // An account earns them, as before.
        $alice = $this->createUserIn('alice@example.com');
        foreach (['1-2', '1-3'] as $step) {
            $this->completed($alice, $step);
        }
        $this->playExercise($alice);
        self::assertSame(60, $this->playExercise($alice)['crystals']);
        self::assertSame(60, $this->balanceOf($alice));
    }

    public function testAGuestBeatsAnArenaBossWithoutItsCrystals(): void
    {
        $guest = $this->createGuest();
        $this->completed($guest, '1-boss');

        $response = $this->api('POST', '/api/arena/games', $guest, ['boss' => 'jongleur', 'color' => 'white', 'cadence' => '3+2']);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());
        $id = $this->json($response)['id'] ?? null;
        self::assertIsString($id);
        $game = [];
        foreach (self::SCHOLAR as $ply => $move) {
            $game = $this->json($this->api('POST', '/api/arena/games/'.$id.'/moves', $guest, ['ply' => $ply, 'move' => $move]));
        }

        self::assertSame(['won', 100], [$game['status'] ?? null, $game['crystals'] ?? null], 'What it would have earned.');
        self::assertSame(0, $this->balanceOf($guest));
    }

    public function testTheUnreachableGuestsArePurged(): void
    {
        $lost = $this->createGuest('2026-09-26 10:00:00');
        $playing = $this->createGuest('2026-09-26 10:00:00');
        $this->refreshToken($playing, '2026-10-05 10:00:00');
        $new = $this->createGuest('2026-09-28 09:00:00');
        $alice = $this->createUserIn('alice@example.com');
        $this->connection()->executeStatement("UPDATE app_user SET created_at = '2026-01-01 00:00:00' WHERE id = ?", [$alice->getId()->toBinary()]);
        $ids = array_map(static fn (User $user): string => $user->getId()->toBinary(), [$lost, $playing, $new, $alice]);

        $tester = new CommandTester((new Application(self::$kernel ?? self::bootKernel()))->find('app:account:purge'));
        $tester->execute([]);

        self::assertStringContainsString('1 guest(s) purged.', $tester->getDisplay());
        $left = array_map(fn (string $id): bool => false !== $this->connection()->fetchOne('SELECT 1 FROM app_user WHERE id = ?', [$id]), $ids);
        self::assertSame([false, true, true, true], $left, 'Lost for good / a session to refresh / a day of grace / an account.');
    }

    public function testTheAdminsFiguresLeaveTheGuestsOut(): void
    {
        $this->createGuest();
        $this->createGuest();
        $admin = $this->createUserIn('admin@example.com');
        $admin->setRoles(['ROLE_ADMIN']);
        $this->entityManager->flush();

        $response = $this->api('GET', '/api/admin/stats', $admin);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $signups = $this->json($response)['signups'] ?? null;
        self::assertIsArray($signups);
        self::assertSame([1, 2], [$signups['accounts'] ?? null, $signups['guests'] ?? null]);
    }

    private function createGuest(?string $createdAt = null): User
    {
        $guest = User::guest();
        $guest->setTimezone('Europe/Paris');
        $this->entityManager->persist($guest);
        $this->entityManager->flush();
        if (null !== $createdAt) {
            $this->connection()->executeStatement('UPDATE app_user SET created_at = ? WHERE id = ?', [$createdAt, $guest->getId()->toBinary()]);
        }

        return $guest;
    }

    /** A session still to refresh until $valid. */
    private function refreshToken(User $user, string $valid): void
    {
        $this->connection()->executeStatement(
            'INSERT INTO refresh_token (refresh_token, username, valid, family_id, family_expires_at) VALUES (?, ?, ?, ?, ?)',
            [bin2hex(random_bytes(32)), $user->getUserIdentifier(), $valid, Uuid::v7()->toBinary(), $valid],
        );
    }

    /**
     * Plays exercise 1.1 of the visualisation once, passed: a one-minute coordinates series of 10
     * right answers, then the time runs out.
     *
     * @return OutcomeJson
     */
    private function playExercise(User $user): array
    {
        $response = $this->api('POST', '/api/mind-map/visualisation/steps/1-1/start', $user);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());
        $run = $this->json($response)['run'] ?? null;
        self::assertIsArray($run);
        $runId = $run['id'] ?? null;
        self::assertIsString($runId);

        $next = $this->json($this->api('POST', '/api/training/runs/'.$runId.'/next', $user));
        /** @var array{id: string, data: array{squares: list<string>}} $item */
        $item = $next['item'];
        $answers = [];
        for ($i = 0; $i < 10; ++$i) {
            $answers[] = ['index' => $i, 'square' => $item['data']['squares'][$i], 'ms' => 1_000];
        }
        $this->travel('+20 seconds');
        $submitted = $this->api('POST', '/api/training/runs/'.$runId.'/submission', $user, ['itemId' => $item['id'], 'answers' => $answers]);
        self::assertSame(200, $submitted->getStatusCode(), (string) $submitted->getContent());
        $this->travel('+45 seconds');

        $response = $this->api('GET', '/api/mind-map/runs/'.$runId, $user);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        /** @var OutcomeJson */
        return $this->json($response);
    }

    /** A step of the visualisation path set as completed. */
    private function completed(User $user, string $step): void
    {
        $this->connection()->executeStatement(
            "INSERT INTO mind_map_progress (id, user_id, capacity, step, passes, completed_at, updated_at) VALUES (?, ?, 'visualisation', ?, 2, '2026-09-28 09:00:00', '2026-09-28 09:00:00')",
            [Uuid::v7()->toBinary(), $user->getId()->toBinary(), $step],
        );
    }

    private function assertRefused(Response $response, int $status, string $reason): void
    {
        self::assertSame($status, $response->getStatusCode(), (string) $response->getContent());
        self::assertStringContainsString($reason, (string) $response->getContent());
    }

    private function balanceOf(User $user): int
    {
        $balance = $this->connection()->fetchOne('SELECT balance FROM shop_wallet WHERE user_id = ?', [$user->getId()->toBinary()]);

        return is_numeric($balance) ? (int) $balance : 0;
    }

    private function xpOf(User $user): int
    {
        $sum = $this->connection()->fetchOne('SELECT COALESCE(SUM(xp), 0) FROM gamification_xp_entry WHERE user_id = ?', [$user->getId()->toBinary()]);

        return is_numeric($sum) ? (int) $sum : 0;
    }

    private function connection(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
