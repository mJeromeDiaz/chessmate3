<?php

declare(strict_types=1);

namespace App\Tests\Functional\Repertoire;

use App\Chess\Pgn\Parser;

/**
 * GET /repertoires/{id}/export: the repertoire as a PGN file.
 */
final class ExportTest extends RepertoireWebTestCase
{
    public function testTheCanonicalTreeIsWrittenWithVariationsAnnotationsAndTranspositions(): void
    {
        $alice = $this->createUser('alice@example.com');
        $repertoire = $this->createRepertoireVia($alice, 'Blancs : 1.d4 — Londres', 'white');
        $id = $repertoire['id'];
        // 1.d4 d5 2.Bf4 Nf6 (2...c5 3.e3) 3.e3, 1.d4 Nf6 2.Bf4 d5 transposing.
        $this->playLine($alice, $id, ['d2d4', 'd7d5', 'c1f4', 'g8f6', 'e2e3']);
        $this->playLine($alice, $id, ['d2d4', 'd7d5', 'c1f4', 'c7c5', 'e2e3']);
        $this->playLine($alice, $id, ['d2d4', 'g8f6', 'c1f4', 'd7d5']);
        $graph = $this->graph($alice, $id);
        $bf4 = array_values(array_filter($graph['moves'], static fn (array $m): bool => 'Bf4' === $m['san'] && $m['canonical']))[0];
        $this->change($alice, '/api/repertoires/'.$id.'/moves/'.$bf4['id'].'/annotation', ['comment' => 'The London {system}', 'nags' => [1]]);

        $this->api('GET', '/api/repertoires/'.$id.'/export', $alice);
        $response = $this->client->getResponse();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/x-chess-pgn; charset=UTF-8', $response->headers->get('Content-Type'));
        self::assertSame('attachment; filename=blancs-1-d4-londres.pgn', $response->headers->get('Content-Disposition'));
        self::assertSame(<<<'PGN'
            [Event "Blancs : 1.d4 — Londres"]
            [Site "ChessMate"]
            [Orientation "white"]
            [Result "*"]

            1. d4 d5 (1... Nf6 2. Bf4 d5) 2. Bf4 $1 {The London {system)} 2... Nf6 (2... c5
            3. e3) 3. e3 *

            PGN, (string) $response->getContent());

        // A PGN reader reads it back.
        $games = (new Parser())->parse((string) $response->getContent());
        self::assertCount(1, $games);
        self::assertSame(['d4'], array_map(static fn ($n) => $n->san, $games[0]->root->children));
    }

    public function testAnotherUsersRepertoireIsNotFound(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $repertoire = $this->createRepertoireVia($alice);

        $this->api('GET', '/api/repertoires/'.$repertoire['id'].'/export', $bob);
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
        $this->client->request('GET', '/api/repertoires/'.$repertoire['id'].'/export');
        self::assertSame(401, $this->client->getResponse()->getStatusCode());
    }
}
