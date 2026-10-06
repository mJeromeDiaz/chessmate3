<?php

declare(strict_types=1);

namespace App\Tests\Functional\Puzzle;

use App\DataFixtures\Puzzle\SamplePuzzles;
use App\Entity\Catalog\Puzzle;
use App\Entity\User;
use App\Puzzle\Selection\SelectionRebuilder;
use App\Puzzle\Theme\ThemeSynchronizer;
use App\Repository\Catalog\PuzzleRepository;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Themes and the ~50 sample puzzles loaded (and indexed for selection) in every test's
 * transaction, which DAMA rolls back afterwards. They live in the catalogue's database
 * (docs/DEPLOY_OVH.md, § 3), through its own entity manager ({@see self::$catalog}).
 *
 * @phpstan-type PuzzleJson array{id: string, fen: string, moves: list<string>, playerColor: string, rating: int, themes: list<string>, gameUrl: string}
 * @phpstan-type AttemptJson array{id: string, status: string, rated: bool, mistakes: int, hintLevel: int, solutionShown: bool, durationMs: int|null, ratingBefore: float|int|null, ratingAfter: float|int|null, ratingDelta: float|int|null, puzzle: PuzzleJson}
 * @phpstan-type HistoryJson array{totalItems: int, member: list<AttemptJson>}
 */
abstract class PuzzleWebTestCase extends WebTestCase
{
    protected KernelBrowser $client;
    protected EntityManagerInterface $entityManager;
    protected EntityManagerInterface $catalog;

    /** @var array<string, Puzzle> by Lichess id */
    protected array $puzzles = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = self::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->catalog = $container->get('doctrine.orm.catalog_entity_manager');
        $container->get('cache.rate_limiter')->clear();

        $container->get(ThemeSynchronizer::class)->sync();
        foreach (SamplePuzzles::create() as $puzzle) {
            $this->catalog->persist($puzzle);
            $this->puzzles[$puzzle->getLichessId()] = $puzzle;
        }
        $this->catalog->flush();
        $container->get(SelectionRebuilder::class)->addPuzzles(array_values(array_map(
            static fn (Puzzle $puzzle): int => (int) $puzzle->getId(),
            $this->puzzles,
        )));

        // The rebuilder writes in plain SQL (theme counts, selectable flag): drop the entities
        // loaded so far so nothing reads stale values from the identity map, then reload.
        $this->catalog->clear();
        $this->puzzles = [];
        foreach ($container->get(PuzzleRepository::class)->findAll() as $puzzle) {
            $this->puzzles[$puzzle->getLichessId()] = $puzzle;
        }
    }

    /**
     * The sample puzzles that pass the quality thresholds (two real ones do not).
     *
     * @return list<Puzzle>
     */
    protected function selectablePuzzles(): array
    {
        return array_values(array_filter($this->puzzles, static fn (Puzzle $puzzle): bool => $puzzle->isSelectable()));
    }

    /**
     * A sample puzzle by catalogue id: tests read the catalogue apart, never through a join with
     * the main database (docs/DEPLOY_OVH.md, § 3).
     */
    protected function puzzleById(int $id): Puzzle
    {
        foreach ($this->puzzles as $puzzle) {
            if ($puzzle->getId() === $id) {
                return $puzzle;
            }
        }

        throw new \LogicException(\sprintf('No sample puzzle %d.', $id));
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

    protected function tokenFor(User $user): string
    {
        return self::getContainer()->get(JWTTokenManagerInterface::class)->create($user);
    }

    /**
     * @param array<string, mixed>|null $body
     */
    protected function api(string $method, string $uri, User $user, ?array $body = null): Response
    {
        $this->client->request($method, $uri, server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->tokenFor($user),
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
     * @return AttemptJson
     */
    protected function attemptJson(Response $response): array
    {
        /** @var AttemptJson */
        return $this->json($response);
    }

    /**
     * @return HistoryJson
     */
    protected function historyJson(Response $response): array
    {
        /** @var HistoryJson */
        return $this->json($response);
    }

    /**
     * Starts a rated attempt and returns its JSON.
     *
     * @param array<string, mixed> $criteria
     *
     * @return AttemptJson
     */
    protected function startAttempt(User $user, array $criteria = []): array
    {
        $response = $this->api('POST', '/api/puzzles/attempts', $user, $criteria);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());

        return $this->attemptJson($response);
    }

    /**
     * The player's moves of the solution (every other move from the second).
     *
     * @param AttemptJson $attempt
     *
     * @return list<string>
     */
    protected static function playerMoves(array $attempt): array
    {
        $moves = [];
        foreach ($attempt['puzzle']['moves'] as $i => $move) {
            if (1 === $i % 2) {
                $moves[] = $move;
            }
        }

        return $moves;
    }
}
