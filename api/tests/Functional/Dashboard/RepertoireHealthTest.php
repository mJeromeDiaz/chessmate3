<?php

declare(strict_types=1);

namespace App\Tests\Functional\Dashboard;

use App\Entity\User;
use App\Tests\Functional\Repertoire\RepertoireRunTrait;
use App\Tests\Functional\Repertoire\RepertoireWebTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;

/**
 * The repertoire block of the dashboard (docs/DASHBOARD.md), on tests played through the training
 * API: cards, tests of the period, fragile segments of every repertoire.
 */
final class RepertoireHealthTest extends RepertoireWebTestCase
{
    use RepertoireRunTrait;

    private const ITALIAN = '1. e4 e5 (1... c5 2. Nf3 d6 3. d4) 2. Nf3 Nc6 3. Bc4 *';

    private MockClock $clock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new MockClock('2026-10-01 10:00:00', 'UTC');
        Clock::set($this->clock);
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        parent::tearDown();
    }

    public function testFragileSegmentsAndTestsOfThePeriod(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $italian = $this->repertoire($alice, self::ITALIAN);
        $id = $italian->getId()->toRfc4122();
        $c5 = $this->segmentOf($italian, 'e4', 'c5');
        $e5 = $this->segmentOf($italian, 'e4', 'e5');

        foreach ([true, true, false, true] as $fail) {
            $run = $this->start($alice, ['repertoireIds' => [$id], 'segmentIds' => [$c5]]);
            $this->playUnit($alice, $run['id'], fail: $fail);
            $this->stop($alice, $run['id']);
            $this->clock->modify('+1 day');
        }
        foreach ([1, 2, 3] as $ignored) {
            $run = $this->start($alice, ['repertoireIds' => [$id], 'segmentIds' => [$e5]]);
            $this->playUnit($alice, $run['id']);
            $this->stop($alice, $run['id']);
        }

        $body = $this->health($alice, 7);
        self::assertSame(1, $body['repertoires']);
        self::assertSame(5, $body['cards']['total']);
        self::assertEquals(['total' => 7, 'succeeded' => 4, 'successRate' => 0.5714], $body['tests']);
        self::assertCount(1, $body['fragile'], 'the 1…e5 segment never failed');
        $fragile = $body['fragile'][0];
        $label = json_encode($fragile['label'], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);
        self::assertStringContainsString('"name":"Sicilian Defense"', $label, 'labelled as at its last test');
        self::assertStringContainsString('"move":"1…c5"', $label);
        unset($fragile['label']);
        self::assertEquals([
            'repertoireId' => $id,
            'repertoireName' => 'Test',
            'color' => 'white',
            'segmentId' => $c5,
            'tests' => 4,
            'recentFailureRate' => 0.75,
        ], $fragile);

        // Ten days later: no test in the last 7 days, the segment is still fragile.
        $this->clock->modify('+10 days');
        $body = $this->health($alice, 7);
        self::assertEquals(['total' => 0, 'succeeded' => 0, 'successRate' => null], $body['tests']);
        self::assertSame([$c5], array_column($body['fragile'], 'segmentId'));

        $body = $this->health($bob, 30);
        self::assertSame([0, ['total' => 0, 'new' => 0, 'due' => 0], []], [$body['repertoires'], $body['cards'], $body['fragile']]);
    }

    /**
     * @return array{repertoires: int, cards: array{total: int, new: int, due: int}, tests: array<string, mixed>, fragile: list<array<string, mixed>>}
     */
    private function health(User $user, int $days): array
    {
        $response = $this->api('GET', '/api/dashboard/repertoire?days='.$days, $user);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        /** @var array{repertoires: int, cards: array{total: int, new: int, due: int}, tests: array<string, mixed>, fragile: list<array<string, mixed>>} $body */
        $body = $this->json($response);

        return $body;
    }
}
