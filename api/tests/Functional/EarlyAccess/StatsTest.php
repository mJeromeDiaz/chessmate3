<?php

declare(strict_types=1);

namespace App\Tests\Functional\EarlyAccess;

use App\Activity\Event\ExerciseCompleted;
use App\EarlyAccess\Invitation\KeyGenerator;
use App\Entity\Activity\LogEntry;
use App\Entity\AuthIdentity;
use App\Entity\EarlyAccess\InvitationKey;
use App\Entity\User;
use App\Enum\Activity\ExerciseType;
use App\Enum\AuthProvider;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /api/admin/stats and GET /api/admin/users (docs/EARLY_ACCESS.md). The clock is at
 * 2026-10-05 10:00 UTC (a Monday), the admin in Europe/Paris.
 *
 * @phpstan-type StatsJson array{
 *     timezone: string,
 *     from: string,
 *     today: string,
 *     invitations: array<string, mixed>,
 *     signups: array{total: int, byMethod: array<string, int>, days: list<array{date: string, total: int, byMethod: array<string, int>}>, accounts: int, unverified: int, suspended: int},
 *     activity: array{players: int, active: int, inactive: int, days: list<array{date: string, activePlayers: int}>, weeks: list<array<string, mixed>>, averageWeeklyMs: int|null},
 *     ratings: array{bands: list<array{from: int, count: int}>, bandWidth: int, median: int|null, rated: int, linkedUnrated: int, notLinked: int, byPerf: array<string, int>},
 * }
 */
final class StatsTest extends EarlyAccessWebTestCase
{
    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setCreatedAt($this->admin, '2026-01-01 00:00:00');
    }

    public function testOnlyAdminsReadTheStatistics(): void
    {
        $player = $this->createUser('player@example.com');

        foreach (['/api/admin/stats', '/api/admin/users'] as $uri) {
            self::assertSame(401, $this->api('GET', $uri)->getStatusCode(), "$uri anonymous");
            self::assertSame(403, $this->api('GET', $uri, $player)->getStatusCode(), "$uri as a player");
            self::assertSame(200, $this->api('GET', $uri, $this->admin)->getStatusCode(), "$uri as an admin");
        }
    }

    public function testInvitationFunnel(): void
    {
        $used = $this->invite('used@example.com');
        $this->deliver();
        $this->invite('expiring@example.com', ['expiresAt' => '2026-10-05T10:30:00+00:00']);
        $revoked = $this->invite('revoked@example.com');
        $this->invite('pending@example.com');
        $this->oldInvitation();

        $this->clock->modify('+1 hour');
        self::assertSame(202, $this->register('newcomer@example.com', (string) $used['key'])->getStatusCode());
        self::assertSame(204, $this->api('DELETE', '/api/admin/invitation-keys/'.$revoked['id'], $this->admin)->getStatusCode());

        $invitations = $this->stats()['invitations'];

        self::assertSame([
            'created' => 4,
            'pending' => 1,
            'used' => 1,
            'expired' => 1,
            'revoked' => 1,
            'emailSent' => 1,
            'emailFailed' => 0,
            'conversionRate' => 0.25,
            'medianSecondsToUse' => 3600,
            // The pending one, and the old one that never expires.
            'pendingNow' => 2,
        ], $invitations);
    }

    public function testSignupsByLocalDayAndMethod(): void
    {
        $invitation = $this->invite();
        self::assertSame(202, $this->register('night@example.com', (string) $invitation['key'])->getStatusCode());
        // 00:30 in Paris: the admin's 5 October.
        $this->setCreatedAt($this->userByEmail('night@example.com'), '2026-10-04 22:30:00');
        $this->setCreatedAt($this->createUser('seeded@example.com'), '2026-10-03 12:00:00');
        $this->setCreatedAt($this->createUser('old@example.com'), '2026-08-01 12:00:00');

        $stats = $this->stats(7);
        $signups = $stats['signups'];

        self::assertSame(['Europe/Paris', '2026-09-29', '2026-10-05'], [$stats['timezone'], $stats['from'], $stats['today']]);
        self::assertSame(2, $signups['total']);
        self::assertSame(['password' => 1, 'google' => 0, 'lichess' => 0, 'other' => 1], $signups['byMethod']);
        self::assertCount(7, $signups['days']);
        $days = array_column($signups['days'], null, 'date');
        self::assertSame(['date' => '2026-10-05', 'total' => 1, 'byMethod' => ['password' => 1, 'google' => 0, 'lichess' => 0, 'other' => 0]], $days['2026-10-05']);
        self::assertSame(1, $days['2026-10-03']['byMethod']['other']);
        self::assertSame(0, $days['2026-10-04']['total']);
        // The admin, the three accounts; only the one registered with a password is unverified.
        self::assertSame(4, $signups['accounts']);
        self::assertSame(1, $signups['unverified']);
        self::assertSame(0, $signups['suspended']);
    }

    public function testCommunityActivity(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $carol = $this->createUser('carol@example.com');
        $this->exercise($alice, '2026-10-05 08:00:00', 600_000);
        // Before now - 7 days: does not make Alice active, but counts in its week.
        $this->exercise($alice, '2026-09-28 08:00:00', 300_000);
        $this->exercise($bob, '2026-10-01 12:00:00', 1_200_000);
        $this->exercise($carol, '2026-09-20 12:00:00', 900_000);

        $activity = $this->stats(14)['activity'];

        self::assertSame(4, $activity['players']);
        self::assertSame(2, $activity['active']);
        self::assertSame(2, $activity['inactive']);
        self::assertCount(14, $activity['days']);
        $days = array_column($activity['days'], 'activePlayers', 'date');
        self::assertSame(1, $days['2026-10-05']);
        self::assertSame(1, $days['2026-10-01']);
        self::assertSame(1, $days['2026-09-28']);
        self::assertSame(3, array_sum($days));
        self::assertSame([
            ['start' => '2026-09-21', 'activePlayers' => 0, 'durationMs' => 0, 'averageMs' => null],
            ['start' => '2026-09-28', 'activePlayers' => 2, 'durationMs' => 1_500_000, 'averageMs' => 750_000],
            ['start' => '2026-10-05', 'activePlayers' => 1, 'durationMs' => 600_000, 'averageMs' => 600_000],
        ], $activity['weeks']);
        // 2.1 M ms over 3 player-weeks.
        self::assertSame(700_000, $activity['averageWeeklyMs']);
    }

    public function testLichessRatingDistribution(): void
    {
        $this->lichess($this->createUser('rapid@example.com'), 'rapid', ['rapid' => [1850, false]]);
        $this->lichess($this->createUser('blitz@example.com'), 'blitz', ['rapid' => [1500, true], 'blitz' => [1720, false]]);
        $this->lichess($this->createUser('classical@example.com'), 'classical', ['classical' => [2010, false]]);
        $this->lichess($this->createUser('unrated@example.com'), 'unrated', ['bullet' => [2400, false]]);
        $this->createUser('google@example.com');

        $ratings = $this->stats()['ratings'];

        self::assertSame([
            ['from' => 1700, 'count' => 1],
            ['from' => 1800, 'count' => 1],
            ['from' => 1900, 'count' => 0],
            ['from' => 2000, 'count' => 1],
        ], $ratings['bands']);
        self::assertSame(100, $ratings['bandWidth']);
        self::assertSame(1850, $ratings['median']);
        self::assertSame(3, $ratings['rated']);
        self::assertSame(1, $ratings['linkedUnrated']);
        // The admin and the Google player.
        self::assertSame(2, $ratings['notLinked']);
        self::assertSame(['rapid' => 1, 'blitz' => 1, 'classical' => 1], $ratings['byPerf']);
    }

    public function testThePeriodIsClamped(): void
    {
        $stats = $this->stats(5000);

        self::assertSame('2025-09-30', $stats['from']);
        self::assertCount(371, $stats['signups']['days']);
    }

    public function testPlayerList(): void
    {
        $invitation = $this->invite();
        self::assertSame(202, $this->register('alice@example.com', (string) $invitation['key'])->getStatusCode());
        $alice = $this->userByEmail('alice@example.com');
        $this->lichess($alice, 'alice', ['rapid' => [1650, false]]);
        $this->exercise($alice, '2026-10-04 12:00:00', 120_000);
        $this->exercise($alice, '2026-08-01 12:00:00', 999_000);
        $bob = $this->createUser('bob@example.com');
        $this->exercise($bob, '2026-09-20 12:00:00', 60_000);

        $response = $this->api('GET', '/api/admin/users?search=ALI', $this->admin);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $members = $this->json($response)['member'] ?? null;
        self::assertIsArray($members);
        self::assertCount(1, $members);
        $row = $members[0];
        self::assertIsArray($row);
        self::assertSame('alice@example.com', $row['email']);
        self::assertSame('password', $row['signupMethod']);
        self::assertSame($invitation['keyHint'], $row['keyHint']);
        self::assertFalse($row['emailVerified']);
        self::assertFalse($row['isAdmin']);
        self::assertTrue($row['active']);
        self::assertSame(120_000, $row['trainingMs30d']);
        self::assertSame(['username' => 'alice', 'perf' => 'rapid', 'rating' => 1650], $row['lichess']);
        self::assertIsString($row['lastActivityAt']);
        self::assertStringStartsWith('2026-10-04T12:00:00', $row['lastActivityAt']);

        $all = $this->json($this->api('GET', '/api/admin/users?itemsPerPage=2', $this->admin));
        self::assertSame(3, $all['totalItems'] ?? null);
        $members = $all['member'] ?? null;
        self::assertIsArray($members);
        self::assertCount(2, $members);
        // Newest first: Bob, then Alice; the admin is on the next page.
        self::assertSame(['bob@example.com', 'alice@example.com'], array_column($members, 'email'));
        $bobRow = $members[0];
        self::assertIsArray($bobRow);
        self::assertSame('other', $bobRow['signupMethod']);
        // Played 15 days ago: within the 30 days of training time, not the 7 days of activity.
        self::assertFalse($bobRow['active']);
        self::assertSame(60_000, $bobRow['trainingMs30d']);
        self::assertNull($bobRow['lichess']);
    }

    /**
     * @return StatsJson
     */
    private function stats(?int $days = null): array
    {
        $response = $this->api('GET', '/api/admin/stats'.(null === $days ? '' : '?days='.$days), $this->admin);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        /** @var StatsJson */
        return $this->json($response);
    }

    private function register(string $email, string $key): Response
    {
        $this->client->request('POST', '/api/auth/register', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'email' => $email,
            'password' => 'CorrectHorseBatteryStaple9!',
            'invitationKey' => $key,
        ], \JSON_THROW_ON_ERROR));

        return $this->client->getResponse();
    }

    private function userByEmail(string $email): User
    {
        $this->entityManager->clear();
        $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
        self::assertInstanceOf(User::class, $user);

        return $user;
    }

    private function setCreatedAt(User $user, string $utc): void
    {
        self::getContainer()->get(Connection::class)->executeStatement(
            'UPDATE app_user SET created_at = :at WHERE id = :id',
            ['at' => $utc, 'id' => $user->getId()->toBinary()],
        );
    }

    /** An invitation created long before the period, that never expires. */
    private function oldInvitation(): void
    {
        $key = (new KeyGenerator())->generate();
        $this->entityManager->persist(new InvitationKey('old@example.com', KeyGenerator::hash($key), KeyGenerator::hint($key), null, new \DateTimeImmutable('2026-06-01 10:00:00', new \DateTimeZone('UTC')), null));
        $this->entityManager->flush();
    }

    private function exercise(User $user, string $utc, int $durationMs): void
    {
        $user = $this->managed($user);
        $this->entityManager->persist(new LogEntry($user, new ExerciseCompleted(
            $user->getId()->toRfc4122(), ExerciseType::PuzzleRated, true, $durationMs, 1, 'test', (string) ++$this->sequence, new \DateTimeImmutable($utc, new \DateTimeZone('UTC')),
        )));
        $this->entityManager->flush();
    }

    /**
     * @param array<string, array{int, bool}> $ratings perf => [rating, provisional]
     */
    private function lichess(User $user, string $username, array $ratings): void
    {
        $user = $this->managed($user);
        $identity = new AuthIdentity($user, AuthProvider::Lichess, $username);
        $identity->setMetadata(['username' => $username, 'ratings' => array_map(
            static fn (array $rating): array => ['rating' => $rating[0], 'games' => 50, 'provisional' => $rating[1]],
            $ratings,
        )]);
        $this->entityManager->persist($identity);
        $this->entityManager->flush();
    }

    private function managed(User $user): User
    {
        $managed = $this->entityManager->find(User::class, $user->getId());
        self::assertInstanceOf(User::class, $managed);

        return $managed;
    }
}
