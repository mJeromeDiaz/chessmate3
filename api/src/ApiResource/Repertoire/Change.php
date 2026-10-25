<?php

declare(strict_types=1);

namespace App\ApiResource\Repertoire;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\NotExposed;
use ApiPlatform\Metadata\Post;
use App\Repertoire\Graph\GraphReader;
use App\State\Repertoire\ChangeProcessor;

/**
 * The result of a change of a repertoire's graph (App\Repertoire\Graph\GraphEditor): the new
 * version and the current state of everything it touched, derived data included, to merge into
 * the client's copy of the graph. Each change accepts the version it is based on (baseVersion):
 * 409 if the repertoire changed meanwhile.
 *
 * @phpstan-import-type PositionRow from GraphReader
 * @phpstan-import-type MoveRow from GraphReader
 * @phpstan-import-type SegmentRow from GraphReader
 */
#[ApiResource(
    shortName: 'RepertoireChange',
    normalizationContext: ['skip_null_values' => false],
    operations: [
        new Post(uriTemplate: '/repertoires/{id}/lines', requirements: ['id' => Repertoire::UUID_PATTERN], status: 200, input: SaveLineInput::class, read: false, name: 'repertoire_line_save', processor: ChangeProcessor::class),
        new Post(uriTemplate: '/repertoires/{id}/moves/{moveId}/replace', requirements: ['id' => Repertoire::UUID_PATTERN, 'moveId' => Repertoire::UUID_PATTERN], status: 200, input: ReplaceMoveInput::class, read: false, name: 'repertoire_move_replace', processor: ChangeProcessor::class),
        new Post(uriTemplate: '/repertoires/{id}/moves/{moveId}/promote', requirements: ['id' => Repertoire::UUID_PATTERN, 'moveId' => Repertoire::UUID_PATTERN], status: 200, input: ChangeInput::class, read: false, name: 'repertoire_move_promote', processor: ChangeProcessor::class),
        new Post(uriTemplate: '/repertoires/{id}/moves/{moveId}/annotation', requirements: ['id' => Repertoire::UUID_PATTERN, 'moveId' => Repertoire::UUID_PATTERN], status: 200, input: AnnotateInput::class, read: false, name: 'repertoire_move_annotate', processor: ChangeProcessor::class),
        new Post(uriTemplate: '/repertoires/{id}/moves/{moveId}/delete', requirements: ['id' => Repertoire::UUID_PATTERN, 'moveId' => Repertoire::UUID_PATTERN], status: 200, input: ChangeInput::class, read: false, name: 'repertoire_move_delete', processor: ChangeProcessor::class),
        // The IRI of a change (never served: 404).
        new NotExposed(uriTemplate: '/repertoires/{id}/change', requirements: ['id' => Repertoire::UUID_PATTERN]),
        new Post(uriTemplate: '/repertoires/{id}/trash/{trashId}/restore', requirements: ['id' => Repertoire::UUID_PATTERN, 'trashId' => Repertoire::UUID_PATTERN], status: 200, input: RestoreInput::class, read: false, name: 'repertoire_trash_restore', processor: ChangeProcessor::class),
        new Post(uriTemplate: '/repertoires/{id}/pending/{pendingId}/answer', requirements: ['id' => Repertoire::UUID_PATTERN, 'pendingId' => Repertoire::UUID_PATTERN], status: 200, input: AnswerPendingInput::class, read: false, name: 'repertoire_pending_answer', processor: ChangeProcessor::class),
        new Post(uriTemplate: '/repertoires/{id}/undo', requirements: ['id' => Repertoire::UUID_PATTERN], status: 200, input: ChangeInput::class, read: false, name: 'repertoire_undo', processor: ChangeProcessor::class),
    ],
)]
final class Change
{
    /** The repertoire id. */
    #[ApiProperty(identifier: true)]
    public string $id;
    public int $version;
    /** replace, delete, promote, annotate, import (a pending position answered or a line saved too), restore, undo; none when nothing changed */
    public string $operation;
    /** The new prepared move of a replacement. */
    public ?string $moveId = null;
    /** The new move reached a position already in the repertoire through another move order. */
    public bool $transposition = false;
    /** The trash entry a replacement or a deletion created. */
    public ?string $trashId = null;
    /** @var list<PositionRow> */
    public array $positions = [];
    /** @var list<MoveRow> */
    public array $moves = [];
    /** @var list<SegmentRow> active segments of the moves above */
    public array $segments = [];
    /** @var list<string> */
    public array $deletedPositionIds = [];
    /** @var list<string> */
    public array $deletedMoveIds = [];
}
