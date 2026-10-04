<?php

declare(strict_types=1);

namespace App\Tests\Functional\Training;

use App\Entity\User;
use App\Tests\Functional\Woodpecker\WoodpeckerWebTestCase;
use App\Training\Event\SessionClosed;
use Symfony\Component\HttpFoundation\Response;

/**
 * Training sessions (docs/TRAINING.md): a program checked at launch, played step by step (each step
 * a timed run whose parent is the session), steps that cannot start, one active session, and the
 * end of the local day (rules validated on 2026-10-04).
 *
 * @phpstan-type SessionRunJson array{id: string, module: string, subjectId: string, budgetSeconds: int, parentId: string|null}
 * @phpstan-type StepJson array{index: int, module: string, minutes: int, notes: string, settings: array<string, mixed>, status: string, runId: string|null, blocked: array{reason: string, message: string}|null, summary: array{durationMs: int, itemCount: int}|null}
 * @phpstan-type SessionJson array{id: string, title: string, description: string, status: string, currentIndex: int, startedAt: string, expiresAt: string, closedAt: string|null, durationMs: int, steps: list<StepJson>}
 */
final class SessionTest extends WoodpeckerWebTestCase
{
    public function testASessionIsPlayedStepByStep(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $session = $this->launch($alice, [
            ['module' => 'free', 'minutes' => 5, 'notes' => 'Chapitre 2', 'settings' => ['format' => 'book']],
            ['module' => 'puzzles', 'minutes' => 5, 'settings' => ['themes' => ['fork']]],
        ], 'Mardi soir');
        self::assertSame(['Mardi soir', 'active', 0, ['pending', 'pending']], [$session['title'], $session['status'], $session['currentIndex'], array_column($session['steps'], 'status')]);
        // The end of the local day in Paris (UTC+2 in September).
        self::assertSame('2026-09-28T22:00:00+00:00', $session['expiresAt']);
        self::assertSame($session['id'], $this->current($alice)['id'] ?? null);

        $first = $this->nextStep($alice, $session['id']);
        self::assertSame(['free', $session['id'], 300], [$first['run']['module'], $first['run']['parentId'] ?? null, $first['run']['budgetSeconds']]);
        self::assertSame(['running', $first['run']['id']], [$first['session']['steps'][0]['status'], $first['session']['steps'][0]['runId']]);
        self::assertSame($first['run']['id'], $this->nextStep($alice, $session['id'])['run']['id'], 'The step in progress is handed back.');
        self::assertSame(409, $this->api('POST', '/api/training/sessions/'.$session['id'].'/skip', $alice)->getStatusCode(), 'A step being played cannot be skipped.');

        $this->travel('+2 minutes');
        $this->api('POST', '/api/training/runs/'.$first['run']['id'].'/stop', $alice);
        $session = $this->getSession($alice, $session['id']);
        self::assertSame([1, 'done', 120_000], [$session['currentIndex'], $session['steps'][0]['status'], $session['steps'][0]['summary']['durationMs'] ?? null]);

        $second = $this->nextStep($alice, $session['id']);
        self::assertSame(['puzzles', ['themes' => ['fork']]], [$second['run']['module'], $second['session']['steps'][1]['settings']]);
        $this->takeOutboxMessages();
        $this->travel('+6 minutes');

        $session = $this->getSession($alice, $session['id']);
        self::assertSame(['completed', 2, ['done', 'done'], 420_000], [$session['status'], $session['currentIndex'], array_column($session['steps'], 'status'), $session['durationMs']]);
        self::assertSame(404, $this->api('GET', '/api/training/sessions/current', $alice)->getStatusCode());
        $closed = $this->closedEvents();
        self::assertCount(1, $closed);
        self::assertSame(['completed', 2, 2, 0, 420_000], [$closed[0]->status, $closed[0]->stepCount, $closed[0]->doneCount, $closed[0]->skippedCount, $closed[0]->durationMs]);
        self::assertSame(409, $this->api('POST', '/api/training/sessions/'.$session['id'].'/next', $alice)->getStatusCode());
    }

    public function testEveryStepIsCheckedAtLaunch(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $bob = $this->createUserIn('bob@example.com');
        $bobs = $this->json($this->api('POST', '/api/repertoires', $bob, ['name' => 'Bob', 'color' => 'white']))['id'] ?? null;
        $free = ['module' => 'free', 'minutes' => 5, 'settings' => ['format' => 'book']];

        $refused = [
            'no step' => [],
            'unknown module' => [['module' => 'chess960', 'minutes' => 5]],
            'too long' => [['module' => 'free', 'minutes' => 61, 'settings' => ['format' => 'book']]],
            'too many' => array_fill(0, 11, $free),
            'unknown theme' => [$free, ['module' => 'puzzles', 'minutes' => 5, 'settings' => ['themes' => ['nope']]]],
            'no light set' => [['module' => 'woodpecker', 'minutes' => 5]],
            "another user's repertoire" => [['module' => 'repertoire', 'minutes' => 5, 'settings' => ['repertoireIds' => [$bobs]]]],
            'bad format' => [['module' => 'free', 'minutes' => 5, 'settings' => ['format' => 'novel']]],
        ];
        foreach ($refused as $case => $steps) {
            $response = $this->api('POST', '/api/training/sessions', $alice, ['steps' => $steps]);
            self::assertSame(422, $response->getStatusCode(), $case);
        }
        self::assertStringContainsString('Step 2', (string) $this->api('POST', '/api/training/sessions', $alice, ['steps' => $refused['unknown theme']])->getContent());
        self::assertSame(404, $this->api('GET', '/api/training/sessions/current', $alice)->getStatusCode());
    }

    public function testOneActiveSessionAtATime(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $steps = [['module' => 'free', 'minutes' => 5, 'settings' => ['format' => 'video']], ['module' => 'free', 'minutes' => 5, 'settings' => ['format' => 'course']]];
        $session = $this->launch($alice, $steps);

        self::assertSame(409, $this->api('POST', '/api/training/sessions', $alice, ['steps' => $steps])->getStatusCode());

        $run = $this->nextStep($alice, $session['id'])['run'];
        $abandoned = $this->act($alice, $session['id'], 'abandon');
        self::assertSame(['abandoned', ['done', 'unplayed']], [$abandoned['status'], array_column($abandoned['steps'], 'status')]);
        self::assertSame('stopped', $this->json($this->api('GET', '/api/training/runs/'.$run['id'], $alice))['closeReason'] ?? null, 'The run in progress is stopped.');
        self::assertSame(200, $this->api('POST', '/api/training/sessions/'.$session['id'].'/abandon', $alice)->getStatusCode(), 'Idempotent.');

        $this->launch($alice, $steps);
        self::assertSame(['active', 'abandoned'], array_column($this->sessions($alice), 'status'));
    }

    public function testAStepThatCannotStartWaitsForTheUser(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $set = $this->lightSet($alice);
        $session = $this->launch($alice, [['module' => 'woodpecker', 'minutes' => 5], ['module' => 'free', 'minutes' => 5, 'settings' => ['format' => 'other']]]);
        $this->api('POST', '/api/woodpecker/sets/'.$set.'/pause', $alice);

        $response = $this->api('POST', '/api/training/sessions/'.$session['id'].'/next', $alice);
        self::assertSame(409, $response->getStatusCode());
        $session = $this->getSession($alice, $session['id']);
        self::assertSame(['pending', 'light_set_paused'], [$session['steps'][0]['status'], $session['steps'][0]['blocked']['reason'] ?? null]);

        // Resumed in another tab: trying again works.
        $this->api('POST', '/api/woodpecker/sets/'.$set.'/resume', $alice);
        $run = $this->nextStep($alice, $session['id'])['run'];
        self::assertSame([$set, null], [$run['subjectId'], $this->getSession($alice, $session['id'])['steps'][0]['blocked']]);
        $this->api('POST', '/api/training/runs/'.$run['id'].'/stop', $alice);

        $skipped = $this->act($alice, $session['id'], 'skip');
        self::assertSame(['completed', ['done', 'skipped']], [$skipped['status'], array_column($skipped['steps'], 'status')]);
    }

    public function testAnotherRunInProgressBlocksTheNextStep(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $this->api('POST', '/api/training/runs', $alice, ['module' => 'free', 'subjectId' => $alice->getId()->toRfc4122(), 'budgetSeconds' => 300, 'config' => ['format' => 'book']]);
        $session = $this->launch($alice, [['module' => 'free', 'minutes' => 5, 'settings' => ['format' => 'book']]]);

        self::assertSame(409, $this->api('POST', '/api/training/sessions/'.$session['id'].'/next', $alice)->getStatusCode());
        self::assertSame(['pending', null], [$this->getSession($alice, $session['id'])['steps'][0]['status'], $this->getSession($alice, $session['id'])['steps'][0]['blocked']]);
    }

    public function testASessionEndsWithItsLocalDay(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $steps = [['module' => 'free', 'minutes' => 30, 'settings' => ['format' => 'book']], ['module' => 'free', 'minutes' => 5, 'settings' => ['format' => 'book']]];
        $session = $this->launch($alice, $steps);

        // 23:50 in Paris: the first step runs past midnight and is played in full.
        $this->travel('+11 hours 50 minutes');
        $this->nextStep($alice, $session['id']);
        $this->travel('+20 minutes');
        self::assertSame('active', $this->getSession($alice, $session['id'])['status'], 'Not while a run of it is in progress.');

        $this->takeOutboxMessages();
        $this->travel('+15 minutes');
        $expired = $this->getSession($alice, $session['id']);
        self::assertSame(['expired', ['done', 'unplayed'], 1_800_000], [$expired['status'], array_column($expired['steps'], 'status'), $expired['durationMs']]);
        $closed = $this->closedEvents();
        self::assertSame(['expired', 1], [$closed[0]->status ?? null, $closed[0]->doneCount ?? null]);
    }

    public function testAnUntouchedSessionExpiresLazily(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $session = $this->launch($alice, [['module' => 'free', 'minutes' => 5, 'settings' => ['format' => 'book']]]);

        $this->travel('+1 day');
        self::assertSame(404, $this->api('GET', '/api/training/sessions/current', $alice)->getStatusCode());
        self::assertSame(['expired', ['unplayed']], [$this->getSession($alice, $session['id'])['status'], array_column($this->getSession($alice, $session['id'])['steps'], 'status')]);
        $this->launch($alice, [['module' => 'free', 'minutes' => 5, 'settings' => ['format' => 'book']]]);
    }

    public function testSessionsOfOthersAreInvisible(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $mallory = $this->createUserIn('mallory@example.com');
        $session = $this->launch($alice, [['module' => 'free', 'minutes' => 5, 'settings' => ['format' => 'book']]]);

        foreach (['GET /', 'POST /next', 'POST /skip', 'POST /abandon'] as $request) {
            [$method, $suffix] = explode(' ', $request);
            self::assertSame(404, $this->api($method, '/api/training/sessions/'.$session['id'].rtrim($suffix, '/'), $mallory)->getStatusCode(), $request);
        }
        self::assertSame([], $this->sessions($mallory));
        self::assertSame('active', $this->getSession($alice, $session['id'])['status']);
    }

    /**
     * @param list<array<string, mixed>> $steps
     *
     * @return SessionJson
     */
    private function launch(User $user, array $steps, string $title = ''): array
    {
        $response = $this->api('POST', '/api/training/sessions', $user, ['title' => $title, 'steps' => $steps]);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());

        /** @var SessionJson */
        return $this->json($response);
    }

    /**
     * @return array{session: SessionJson, run: SessionRunJson}
     */
    private function nextStep(User $user, string $sessionId): array
    {
        $response = $this->api('POST', '/api/training/sessions/'.$sessionId.'/next', $user);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        /** @var array{session: SessionJson, run: SessionRunJson} */
        return $this->json($response);
    }

    /**
     * @return SessionJson
     */
    private function getSession(User $user, string $sessionId): array
    {
        $response = $this->api('GET', '/api/training/sessions/'.$sessionId, $user);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        /** @var SessionJson */
        return $this->json($response);
    }

    /**
     * @return SessionJson
     */
    private function act(User $user, string $sessionId, string $action): array
    {
        $response = $this->api('POST', '/api/training/sessions/'.$sessionId.'/'.$action, $user);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        /** @var SessionJson */
        return $this->json($response);
    }

    /**
     * @return list<SessionJson>
     */
    private function sessions(User $user): array
    {
        /** @var array{member: list<SessionJson>} $list */
        $list = $this->json($this->api('GET', '/api/training/sessions', $user));

        return $list['member'];
    }

    /**
     * @return SessionJson|null
     */
    private function current(User $user): ?array
    {
        $response = $this->api('GET', '/api/training/sessions/current', $user);
        if (Response::HTTP_NOT_FOUND === $response->getStatusCode()) {
            return null;
        }

        /** @var SessionJson */
        return $this->json($response);
    }

    /**
     * @return list<SessionClosed>
     */
    private function closedEvents(): array
    {
        return array_values(array_filter($this->takeOutboxMessages(), static fn (object $m): bool => $m instanceof SessionClosed));
    }

    private function lightSet(User $user): string
    {
        $response = $this->api('POST', '/api/woodpecker/sets', $user, ['name' => 'Light', 'mode' => 'light', 'ratingMin' => 400, 'ratingMax' => 3200]);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());
        $id = $this->json($response)['id'] ?? null;
        self::assertIsString($id);

        return $id;
    }
}
