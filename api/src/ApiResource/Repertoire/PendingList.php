<?php

declare(strict_types=1);

namespace App\ApiResource\Repertoire;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation;
use App\State\Repertoire\PendingProcessor;
use App\State\Repertoire\PendingProvider;

/**
 * The pending positions of a repertoire built from the user's games (docs/REPERTOIRE.md, "En
 * suspens"): the user to move, reached by a popular reply of the Lichess explorer without a move
 * from their games. Answering one is a change of the repertoire (POST
 * /repertoires/{id}/pending/{pendingId}/answer, a RepertoireChange); dismissing and restoring only
 * change its status. Another user's repertoire: 404.
 *
 * @phpstan-type PendingView array{id: string, fen: string, path: list<string>, reason: string, games: int|null, share: float|null, score: int|null, status: string, answerUci: string|null}
 *                           path: the SAN moves from the initial position; reason: reply_uncovered, no_user_move, short_line;
 *                           games, share (%), score (the user's, %): the reply leading there on Lichess; status: open, answered, dismissed
 */
#[ApiResource(
    shortName: 'RepertoirePendingList',
    normalizationContext: ['skip_null_values' => false],
    operations: [
        new Get(uriTemplate: '/repertoires/{id}/pending', requirements: ['id' => Repertoire::UUID_PATTERN], provider: PendingProvider::class),
        new Post(
            uriTemplate: '/repertoires/{id}/pending/{pendingId}/dismiss',
            requirements: ['id' => Repertoire::UUID_PATTERN, 'pendingId' => Repertoire::UUID_PATTERN],
            status: 200,
            openapi: new Operation(summary: 'Sets a pending position aside (restorable). 409 pending_not_open.'),
            input: false,
            read: false,
            name: 'repertoire_pending_dismiss',
            processor: PendingProcessor::class,
        ),
        new Post(
            uriTemplate: '/repertoires/{id}/pending/{pendingId}/restore',
            requirements: ['id' => Repertoire::UUID_PATTERN, 'pendingId' => Repertoire::UUID_PATTERN],
            status: 200,
            openapi: new Operation(summary: 'Opens a dismissed pending position again. 409 pending_not_open.'),
            input: false,
            read: false,
            name: 'repertoire_pending_restore',
            processor: PendingProcessor::class,
        ),
    ],
)]
final class PendingList
{
    /** The repertoire id. */
    #[ApiProperty(identifier: true)]
    public string $id;
    /** Open ones. */
    public int $open = 0;
    /** @var list<PendingView> the most popular reply first */
    public array $items = [];
}
