<?php

declare(strict_types=1);

namespace App\Tests\Functional\Training;

use App\Entity\User;
use App\Tests\Functional\Woodpecker\WoodpeckerWebTestCase;
use Doctrine\DBAL\Connection;

/**
 * Saved sessions (docs/TRAINING.md): program and settings stored, consistency checked, next
 * occurrence, launch into a played session; changing or deleting one only changes the future.
 *
 * @phpstan-type PlanJson array{id: string, title: string, steps: list<array<string, mixed>>, totalMinutes: int, repetition: string, time: string|null, weekdays: list<int>, public: bool, reminderEnabled: bool, reminderChannels: list<string>, reminderMinutes: int, calendarEnabled: bool, nextAt: string|null}
 */
final class PlanTest extends WoodpeckerWebTestCase
{
    private const FREE = ['module' => 'free', 'minutes' => 10, 'settings' => ['format' => 'book']];

    public function testASavedSessionKeepsItsProgramAndSettings(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $plan = $this->save($alice, [
            'title' => 'Soirs de semaine',
            'steps' => [self::FREE, ['module' => 'puzzles', 'minutes' => 20, 'settings' => ['themes' => ['fork']]]],
            'repetition' => 'daily',
            'time' => '18:30',
            'weekdays' => [1, 2, 3, 4, 5],
            'public' => true,
            'reminderEnabled' => true,
            'reminderChannels' => ['email', 'push'],
            'reminderMinutes' => 30,
            'calendarEnabled' => true,
        ]);

        self::assertSame(['Soirs de semaine', 30, 'daily', '18:30', [1, 2, 3, 4, 5], true, true, ['email', 'push'], 30, true], [
            $plan['title'], $plan['totalMinutes'], $plan['repetition'], $plan['time'], $plan['weekdays'], $plan['public'],
            $plan['reminderEnabled'], $plan['reminderChannels'], $plan['reminderMinutes'], $plan['calendarEnabled'],
        ]);
        // Monday 12:00 in Paris: this evening at 18:30 (UTC+2).
        self::assertSame('2026-09-28T16:30:00+00:00', $plan['nextAt']);
        self::assertSame([$plan['id']], array_column($this->plans($alice), 'id'));

        $changed = $this->save($alice, ['title' => 'À la demande', 'steps' => [self::FREE], 'repetition' => 'on_demand', 'time' => '18:30', 'weekdays' => [1], 'reminderEnabled' => true, 'reminderChannels' => ['email'], 'calendarEnabled' => true], $plan['id']);
        self::assertSame(['on_demand', null, [], false, false, null], [$changed['repetition'], $changed['time'], $changed['weekdays'], $changed['reminderEnabled'], $changed['calendarEnabled'], $changed['nextAt']], 'No time: no reminder, no calendar.');
    }

    public function testInconsistentSettingsAreRefused(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $refused = [
            'no time' => ['repetition' => 'daily', 'weekdays' => [1]],
            'bad time' => ['repetition' => 'daily', 'time' => '25:00', 'weekdays' => [1]],
            'no day' => ['repetition' => 'daily', 'time' => '18:00', 'weekdays' => []],
            'day 8' => ['repetition' => 'daily', 'time' => '18:00', 'weekdays' => [8]],
            'twice a day' => ['repetition' => 'daily', 'time' => '18:00', 'weekdays' => [1, 1]],
            'weekly, two days' => ['repetition' => 'weekly', 'time' => '18:00', 'weekdays' => [1, 3]],
            'unknown repetition' => ['repetition' => 'monthly'],
            'reminder without channel' => ['repetition' => 'weekly', 'time' => '18:00', 'weekdays' => [1], 'reminderEnabled' => true],
            'unknown channel' => ['reminderChannels' => ['sms']],
            'odd delay' => ['reminderMinutes' => 45],
            'unknown theme' => ['steps' => [['module' => 'puzzles', 'minutes' => 10, 'settings' => ['themes' => ['nope']]]]],
            'no step' => ['steps' => []],
        ];
        foreach ($refused as $case => $body) {
            $response = $this->api('POST', '/api/training/plans', $alice, $body + ['steps' => [self::FREE]]);
            self::assertSame(422, $response->getStatusCode(), $case.': '.$response->getContent());
        }
    }

    public function testASavedSessionMayWaitForItsSubjectButNotItsLaunch(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        // No light set yet: saving is fine, launching is not.
        $plan = $this->save($alice, ['steps' => [['module' => 'woodpecker', 'minutes' => 10]]]);

        $response = $this->api('POST', '/api/training/plans/'.$plan['id'].'/launch', $alice);
        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('Step 1', (string) $response->getContent());
    }

    public function testLaunchingCreatesAPlayedSessionThatOutlivesThePlan(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $plan = $this->save($alice, ['title' => 'Lecture', 'steps' => [self::FREE], 'description' => 'Finales']);

        $response = $this->api('POST', '/api/training/plans/'.$plan['id'].'/launch', $alice);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());
        $session = $this->json($response);
        self::assertSame(['Lecture', 'Finales', $plan['id'], 'active'], [$session['title'] ?? null, $session['description'] ?? null, $session['planId'] ?? null, $session['status'] ?? null]);
        self::assertSame(409, $this->api('POST', '/api/training/plans/'.$plan['id'].'/launch', $alice)->getStatusCode(), 'One active session.');

        // Changing the plan does not change the session launched.
        $this->save($alice, ['title' => 'Autre', 'steps' => [self::FREE, self::FREE]], $plan['id']);
        self::assertIsString($session['id'] ?? null);
        $again = $this->json($this->api('GET', '/api/training/sessions/'.$session['id'], $alice));
        self::assertSame(['Lecture', 1], [$again['title'] ?? null, \is_array($again['steps'] ?? null) ? \count($again['steps']) : null]);

        self::assertSame(204, $this->api('DELETE', '/api/training/plans/'.$plan['id'], $alice)->getStatusCode());
        self::assertSame(404, $this->api('GET', '/api/training/plans/'.$plan['id'], $alice)->getStatusCode());
        $kept = $this->json($this->api('GET', '/api/training/sessions/'.$session['id'], $alice));
        self::assertArrayHasKey('planId', $kept);
        self::assertSame(['Lecture', null], [$kept['title'] ?? null, $kept['planId']]);
    }

    public function testPlansOfOthersAreInvisible(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $mallory = $this->createUserIn('mallory@example.com');
        $plan = $this->save($alice, ['steps' => [self::FREE]]);

        foreach (['GET', 'PUT', 'DELETE'] as $method) {
            $response = $this->api($method, '/api/training/plans/'.$plan['id'], $mallory, 'PUT' === $method ? ['steps' => [self::FREE]] : null);
            self::assertSame(404, $response->getStatusCode(), $method);
        }
        self::assertSame(404, $this->api('POST', '/api/training/plans/'.$plan['id'].'/launch', $mallory)->getStatusCode());
        self::assertSame([], $this->plans($mallory));
    }

    public function testTheNumberOfSavedSessionsIsBounded(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $connection = self::getContainer()->get(Connection::class);
        $this->save($alice, ['steps' => [self::FREE]]);
        // 49 more, directly: the API's rate limit is not what is tested here.
        $connection->executeStatement(
            'INSERT INTO training_session_plan (id, user_id, title, description, steps, repetition, weekdays, reminder_channels, created_at, updated_at)
             SELECT UUID_TO_BIN(UUID()), user_id, title, description, steps, repetition, weekdays, reminder_channels, created_at, updated_at
             FROM training_session_plan, (SELECT 1 FROM information_schema.columns LIMIT 49) AS n',
        );

        self::assertSame(409, $this->api('POST', '/api/training/plans', $alice, ['steps' => [self::FREE]])->getStatusCode());
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return PlanJson
     */
    private function save(User $user, array $body, ?string $id = null): array
    {
        $response = null === $id
            ? $this->api('POST', '/api/training/plans', $user, $body)
            : $this->api('PUT', '/api/training/plans/'.$id, $user, $body);
        self::assertSame(null === $id ? 201 : 200, $response->getStatusCode(), (string) $response->getContent());

        /** @var PlanJson */
        return $this->json($response);
    }

    /**
     * @return list<PlanJson>
     */
    private function plans(User $user): array
    {
        /** @var array{member: list<PlanJson>} $list */
        $list = $this->json($this->api('GET', '/api/training/plans', $user));

        return $list['member'];
    }
}
