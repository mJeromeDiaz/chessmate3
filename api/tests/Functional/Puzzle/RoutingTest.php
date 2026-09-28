<?php

declare(strict_types=1);

namespace App\Tests\Functional\Puzzle;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Routing\RouterInterface;

/**
 * `/puzzles/{id}` must never capture a sibling resource of the domain.
 */
final class RoutingTest extends PuzzleWebTestCase
{
    /**
     * @return iterable<array{string, string, string}>
     */
    public static function routes(): iterable
    {
        yield ['GET', '/api/puzzles/themes', '_api_/puzzles/themes_get_collection'];
        yield ['GET', '/api/puzzles/rating', '_api_/puzzles/rating_get'];
        yield ['POST', '/api/puzzles/rating/lichess-import', '_api_/puzzles/rating/lichess-import_post'];
        yield ['GET', '/api/puzzles/attempts', '_api_/puzzles/attempts_get_collection'];
        yield ['POST', '/api/puzzles/attempts', '_api_/puzzles/attempts_post'];
        yield ['GET', '/api/puzzles/attempts/0192f6a0-1111-7000-8000-000000000000', '_api_/puzzles/attempts/{id}_get'];
        yield ['POST', '/api/puzzles/attempts/0192f6a0-1111-7000-8000-000000000000/submission', '_api_/puzzles/attempts/{id}/submission_post'];
        yield ['GET', '/api/puzzles/K69di', '_api_/puzzles/{id}_get'];
    }

    #[DataProvider('routes')]
    public function testEachPathMatchesItsOwnRoute(string $method, string $path, string $route): void
    {
        $router = self::getContainer()->get(RouterInterface::class);
        $context = $router->getContext();
        $context->setMethod($method);

        self::assertSame($route, $router->match($path)['_route']);
    }

    public function testEndpointsAnswerWithTheirOwnResource(): void
    {
        $user = $this->createUser('alice@example.com');

        /** @var array{member: list<array{'@type': string}>} $themes */
        $themes = $this->json($this->api('GET', '/api/puzzles/themes', $user));
        self::assertSame('PuzzleTheme', $themes['member'][0]['@type']);
        self::assertGreaterThan(70, \count($themes['member']));

        $rating = $this->json($this->api('GET', '/api/puzzles/rating', $user));
        self::assertSame('PuzzleRating', $rating['@type']);
        self::assertSame(1500, $rating['rating']);

        $puzzle = reset($this->puzzles);
        self::assertNotFalse($puzzle);
        $single = $this->json($this->api('GET', '/api/puzzles/'.$puzzle->getLichessId(), $user));
        self::assertSame('Puzzle', $single['@type']);
        self::assertArrayNotHasKey('moves', $single, 'the solution only travels with an attempt');

        self::assertSame(404, $this->api('GET', '/api/puzzles/zzzzz', $user)->getStatusCode());
        self::assertSame(404, $this->api('GET', '/api/puzzles/toolong', $user)->getStatusCode());
        self::assertSame(404, $this->api('GET', '/api/puzzles/attempts/not-a-uuid', $user)->getStatusCode());
    }

    public function testThemeCountsArePrecomputed(): void
    {
        $user = $this->createUser('alice@example.com');
        /** @var array{member: list<array{key: string, puzzleCount: int}>} $themes */
        $themes = $this->json($this->api('GET', '/api/puzzles/themes', $user));

        $counts = array_column($themes['member'], 'puzzleCount', 'key');
        $expected = 0;
        foreach ($this->puzzles as $puzzle) {
            if ($puzzle->isSelectable() && \in_array('mateIn1', $puzzle->getThemes(), true)) {
                ++$expected;
            }
        }
        self::assertSame($expected, $counts['mateIn1']);
        self::assertSame(0, $counts['balestraMate']);
    }
}
