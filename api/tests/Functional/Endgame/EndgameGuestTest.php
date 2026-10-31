<?php

declare(strict_types=1);

namespace App\Tests\Functional\Endgame;

use App\Entity\User;
use App\Tests\Functional\Woodpecker\WoodpeckerWebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * A guest's endgames (docs/GUEST.md): three positions a local day, the module and the paths
 * together, the run closed once they are played; the tablebase only while an endgame run is open.
 */
final class EndgameGuestTest extends WoodpeckerWebTestCase
{
    use KeptAnswersTrait;

    private const START = '8/8/8/P7/8/8/5k2/7K w - - 0 1';
    private const LEARN = ['mode' => 'learn', 'lessons' => ['square-promote'], 'elo' => 1500];

    protected function setUp(): void
    {
        parent::setUp();
        self::keep(self::START, self::winWith('a5a6', 'h1h2', 23));
    }

    public function testThreePositionsADayThenTheRunCloses(): void
    {
        $guest = $this->createGuest();
        $run = $this->startOk($guest);

        for ($i = 0; $i < 3; ++$i) {
            $item = $this->nextStep($guest, $run)['item'] ?? null;
            self::assertIsArray($item, 'Position '.($i + 1));
            $id = $item['id'] ?? null;
            self::assertIsString($id);
            $submitted = $this->api('POST', '/api/training/runs/'.$run.'/submission', $guest, ['itemId' => $id, 'moves' => [], 'pass' => true]);
            self::assertSame(200, $submitted->getStatusCode(), (string) $submitted->getContent());
        }
        $step = $this->nextStep($guest, $run);
        self::assertNull($step['item'] ?? null, 'No fourth position today.');
        $closed = $step['run'] ?? null;
        self::assertIsArray($closed);
        self::assertSame('subject_finished', $closed['closeReason'] ?? null);

        $this->assertRefused($this->start($guest), 403, 'guest_endgame_quota');

        // The next local day (Paris): three more.
        $this->travel('+1 day');
        $this->startOk($guest);
    }

    public function testTheTablebaseOnlyDuringAnEndgameRun(): void
    {
        $guest = $this->createGuest();
        $uri = '/api/endgames/tablebase?fen='.rawurlencode(self::START);

        $this->assertRefused($this->api('GET', $uri, $guest), 403, 'account_required');
        $this->startOk($guest);
        self::assertSame(200, $this->api('GET', $uri, $guest)->getStatusCode());

        $alice = $this->createUserIn('alice@example.com');
        self::assertSame(200, $this->api('GET', $uri, $alice)->getStatusCode(), 'An account, whenever.');
    }

    private function createGuest(): User
    {
        $guest = User::guest();
        $guest->setTimezone('Europe/Paris');
        $this->entityManager->persist($guest);
        $this->entityManager->flush();

        return $guest;
    }

    private function start(User $user): Response
    {
        return $this->api('POST', '/api/training/runs', $user, ['module' => 'endgames', 'subjectId' => $user->getId()->toRfc4122(), 'budgetSeconds' => 300, 'config' => self::LEARN]);
    }

    private function startOk(User $user): string
    {
        $response = $this->start($user);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());
        $id = $this->json($response)['id'] ?? null;
        self::assertIsString($id);

        return $id;
    }

    /**
     * @return array<string, mixed>
     */
    private function nextStep(User $user, string $runId): array
    {
        $response = $this->api('POST', '/api/training/runs/'.$runId.'/next', $user);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        return $this->json($response);
    }

    private function assertRefused(Response $response, int $status, string $reason): void
    {
        self::assertSame($status, $response->getStatusCode(), (string) $response->getContent());
        self::assertStringContainsString($reason, (string) $response->getContent());
    }
}
