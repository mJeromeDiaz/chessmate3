<?php

declare(strict_types=1);

namespace App\ApiResource\Repertoire;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\Parameter;
use App\Entity\Repertoire\Import as ImportEntity;
use App\Repertoire\Import\ImportedTree;
use App\Repertoire\Import\ImportPlan;
use App\State\Repertoire\ApplyImportProcessor;
use App\State\Repertoire\CreateImportProcessor;
use App\State\Repertoire\ImportProvider;

/**
 * A PGN import of the current user (docs/REPERTOIRE.md, "Import"): created from a PGN text,
 * analysed (at once when small, else by a worker: poll it), previewed against a destination
 * (`?repertoireId=` an existing repertoire, or `?color=` a new one; `?choices[fen]=uci` for the
 * conflicts, a choice revealing the conflicts further on), applied. Another user's, or
 * an expired one: 404.
 *
 * @phpstan-import-type ImportWarning from ImportedTree
 * @phpstan-import-type Conflict from ImportPlan
 *
 * @phpstan-type Preview array{repertoireId: string|null, color: string, lines: int, filePositions: int, newPositions: int, newMoves: int, knownMoves: int, positionsAfter: int, maxPositions: int, warnings: list<ImportWarning>, conflicts: list<Conflict>, replaced: int, trashedPositions: int}
 *                        replaced: prepared moves of the repertoire the choices replace (their suites go to the trash)
 */
#[ApiResource(
    shortName: 'RepertoireImport',
    normalizationContext: ['skip_null_values' => false],
    operations: [
        new Post(uriTemplate: '/repertoires/imports', input: CreateImportInput::class, processor: CreateImportProcessor::class),
        new Get(
            uriTemplate: '/repertoires/imports/{id}',
            requirements: ['id' => Repertoire::UUID_PATTERN],
            openapi: new Operation(parameters: [
                new Parameter('repertoireId', 'query', 'Preview against this repertoire of the user', schema: ['type' => 'string']),
                new Parameter('color', 'query', 'Preview for a new repertoire of this color (white, black)', schema: ['type' => 'string']),
                new Parameter('choices', 'query', 'choices[<normalized FEN>]=<UCI>: the move kept where the preview shows a conflict', schema: ['type' => 'object'], style: 'deepObject', explode: true),
            ]),
            provider: ImportProvider::class,
        ),
        new Post(uriTemplate: '/repertoires/imports/{id}/apply', requirements: ['id' => Repertoire::UUID_PATTERN], status: 200, input: ApplyImportInput::class, read: false, name: 'repertoire_import_apply', processor: ApplyImportProcessor::class),
    ],
)]
final class Import
{
    #[ApiProperty(identifier: true)]
    public string $id;
    /** analyzing, analyzed, applying, done, failed */
    public string $status;
    /** 0 to 100 while a worker analyses or applies it. */
    public int $progress;
    /** pgn, study or openbook */
    public string $source;
    public ?string $label = null;
    /** Games or chapters in the file (once analysed). */
    public ?int $games = null;
    /** From the file (Event, Orientation tags), for a new repertoire. */
    public ?string $suggestedName = null;
    public ?string $suggestedColor = null;
    /** Why it failed (too_large, too_many_games, too_many_positions, syntax, empty...), or why the last application was refused (limit_positions, limit_depth, stale...). */
    public ?string $error = null;
    public ?int $errorLine = null;
    /** The repertoire it went into, once done. */
    public ?string $repertoireId = null;
    /** @var Preview|null once analysed */
    public ?array $preview = null;
    public \DateTimeImmutable $expiresAt;

    public static function from(ImportEntity $import, ?ImportPlan $plan = null, ?string $previewRepertoireId = null, ?string $previewColor = null, int $maxPositions = 0): self
    {
        $view = new self();
        $view->id = $import->getId()->toRfc4122();
        $view->status = $import->getStatus()->value;
        $view->progress = $import->getProgress();
        $view->source = $import->getSource();
        $view->label = $import->getLabel();
        $view->error = $import->getError();
        $view->errorLine = $import->getErrorLine();
        $view->repertoireId = $import->getRepertoireId()?->toRfc4122();
        $view->expiresAt = $import->getExpiresAt();
        $tree = $import->getTree();
        if (null !== $tree) {
            $analyzed = ImportedTree::fromStored($tree);
            $view->games = $analyzed->games;
            $view->suggestedName = $analyzed->name;
            $view->suggestedColor = $analyzed->color;
        }
        if (null !== $plan) {
            $view->preview = [
                'repertoireId' => $previewRepertoireId,
                'color' => (string) $previewColor,
                'lines' => $plan->lines,
                'filePositions' => $plan->filePositions,
                'newPositions' => $plan->newPositions,
                'newMoves' => $plan->newMoves,
                'knownMoves' => $plan->knownMoves,
                'positionsAfter' => $plan->positionsAfter,
                'maxPositions' => $maxPositions,
                'warnings' => $plan->warnings,
                'conflicts' => $plan->conflicts,
                'replaced' => \count($plan->replaced),
                'trashedPositions' => $plan->trashedPositions,
            ];
        }

        return $view;
    }
}
