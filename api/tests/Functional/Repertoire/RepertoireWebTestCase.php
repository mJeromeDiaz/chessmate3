<?php

declare(strict_types=1);

namespace App\Tests\Functional\Repertoire;

use App\Entity\User;
use App\Repertoire\Opening\OpeningSynchronizer;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Repertoire API tests: a few opening names loaded (tests/Fixtures/Chess/openings), JSON helpers.
 *
 * @phpstan-type PositionJson array{id: string, fen: string, turn: string, depth: int, opening: array{eco: string, name: string}|null}
 * @phpstan-type MoveJson array{id: string, from: string, to: string, uci: string, san: string, role: string, sortOrder: int, comment: string|null, nags: list<int>, canonical: bool, segmentId: string|null}
 * @phpstan-type SegmentJson array{id: string, startMoveId: string|null, moveCount: int, userMoveCount: int}
 * @phpstan-type RepertoireJson array{id: string, name: string, color: string, positionCount: int, segmentCount: int, version: int, createdAt: string, updatedAt: string}
 * @phpstan-type GraphJson array{id: string, name: string, color: string, version: int, rootPositionId: string, positions: list<PositionJson>, moves: list<MoveJson>, segments: list<SegmentJson>}
 * @phpstan-type TrashSuiteJson array{id: string, reason: string, fromFen: string, uci: string, san: string, path: list<string>, positionCount: int, moveCount: int, createdAt: string}
 * @phpstan-type RestoreConflictJson array{fen: string, path: list<string>, restored: array{uci: string, san: string}, current: array{uci: string, san: string}, choice: string}
 * @phpstan-type TrashPreviewJson array{id: string, reason: string, san: string, restorable: bool, preview: array{conflicts: list<RestoreConflictJson>, positions: int, moves: int, joined: int, leftOut: int, replaced: int}|null}
 * @phpstan-type ChangeJson array{id: string, version: int, operation: string, moveId: string|null, transposition: bool, trashId: string|null, positions: list<PositionJson>, moves: list<MoveJson>, segments: list<SegmentJson>, deletedPositionIds: list<string>, deletedMoveIds: list<string>}
 */
abstract class RepertoireWebTestCase extends WebTestCase
{
    protected KernelBrowser $client;
    protected EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = self::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $container->get('cache.rate_limiter')->clear();
        $container->get(OpeningSynchronizer::class)->sync(__DIR__.'/../../Fixtures/Chess/openings');
    }

    protected function createUser(string $email): User
    {
        $user = new User();
        $user->setEmail($email);
        $user->markEmailVerified();
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    /**
     * @param array<string, mixed>|null $body
     */
    protected function api(string $method, string $uri, User $user, ?array $body = null): Response
    {
        $this->client->request($method, $uri, server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.self::getContainer()->get(JWTTokenManagerInterface::class)->create($user),
            'HTTP_ACCEPT' => 'application/ld+json',
            'CONTENT_TYPE' => 'application/ld+json',
        ], content: null === $body ? null : json_encode($body, \JSON_THROW_ON_ERROR));

        return $this->client->getResponse();
    }

    /**
     * @return array<string, mixed>
     */
    protected function json(Response $response): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR);

        return $decoded;
    }

    /**
     * @return RepertoireJson
     */
    protected function createRepertoireVia(User $user, string $name = 'Blancs : 1.d4', string $color = 'white'): array
    {
        $response = $this->api('POST', '/api/repertoires', $user, ['name' => $name, 'color' => $color]);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());

        /** @var RepertoireJson */
        return $this->json($response);
    }

    /**
     * @return GraphJson
     */
    protected function graph(User $user, string $repertoireId): array
    {
        $response = $this->api('GET', '/api/repertoires/'.$repertoireId.'/graph', $user);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        /** @var GraphJson */
        return $this->json($response);
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return ChangeJson
     */
    protected function change(User $user, string $uri, array $body = [], int $status = 200): array
    {
        $response = $this->api('POST', $uri, $user, $body);
        self::assertSame($status, $response->getStatusCode(), (string) $response->getContent());

        /** @var ChangeJson */
        return $this->json($response);
    }

    /**
     * @return list<TrashSuiteJson>
     */
    protected function trash(User $user, string $repertoireId): array
    {
        $response = $this->api('GET', '/api/repertoires/'.$repertoireId.'/trash', $user);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        /** @var array{suites: list<TrashSuiteJson>} $json */
        $json = $this->json($response);

        return $json['suites'];
    }

    /**
     * @param array<string, string> $choices
     *
     * @return TrashPreviewJson
     */
    protected function trashPreview(User $user, string $repertoireId, string $trashId, array $choices = []): array
    {
        $response = $this->api('GET', '/api/repertoires/'.$repertoireId.'/trash/'.$trashId.([] === $choices ? '' : '?'.http_build_query(['choices' => $choices])), $user);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        /** @var TrashPreviewJson */
        return $this->json($response);
    }

    /**
     * Plays UCI moves from the initial position through the API.
     *
     * @param list<string> $moves
     *
     * @return list<ChangeJson>
     */
    protected function playLine(User $user, string $repertoireId, array $moves): array
    {
        $position = $this->graph($user, $repertoireId)['rootPositionId'];
        $changes = [];
        foreach ($moves as $uci) {
            $change = $this->change($user, '/api/repertoires/'.$repertoireId.'/moves', ['fromPositionId' => $position, 'uci' => $uci]);
            $played = array_values(array_filter($change['moves'], static fn (array $move): bool => $move['id'] === $change['moveId']))[0] ?? null;
            if (null === $played) {
                $played = array_values(array_filter($this->graph($user, $repertoireId)['moves'], static fn (array $move): bool => $move['id'] === $change['moveId']))[0];
            }
            $position = $played['to'];
            $changes[] = $change;
        }

        return $changes;
    }
}
