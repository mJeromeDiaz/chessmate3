<?php

declare(strict_types=1);

namespace App\Repertoire\Import;

/**
 * An import planned against its target ({@see ImportPlanner}): what the preview shows, and what
 * applying it writes.
 *
 * @phpstan-import-type ImportWarning from ImportedTree
 *
 * @phpstan-type Candidate array{uci: string, san: string, origin: 'existing'|'file'|'both'}
 * @phpstan-type Conflict array{fen: string, path: list<string>, candidates: list<Candidate>, choice: string}
 *                       a position where the user would have several moves: the choice is kept, the other file
 *                       moves are not imported, and a replaced move of the repertoire goes to the trash
 * @phpstan-type NewMove array{from: string, to: string, uci: string, san: string, role: string, sortOrder: int, comment: string|null, nags: list<int>}
 */
final class ImportPlan
{
    /** Line ends of the imported moves. */
    public int $lines = 0;
    /** Positions of the file that are imported (the initial one included). */
    public int $filePositions = 0;
    public int $newPositions = 0;
    public int $newMoves = 0;
    /** Moves of the file already in the repertoire. */
    public int $knownMoves = 0;
    /** Positions of the repertoire once the import is applied. */
    public int $positionsAfter = 0;
    /** @var list<ImportWarning> analysis warnings, then planning ones */
    public array $warnings = [];
    /** @var list<Conflict> positions where the user has several moves: the choice is the reference */
    public array $conflicts = [];

    /** @var array<string, string> normalized FEN => side to move, of the positions to create */
    public array $positions = [];
    /** @var list<NewMove> in file order */
    public array $moves = [];
    /** @var array<string, array{comment?: string, nags?: list<int>}> existing move id => what fills its empty annotations */
    public array $fills = [];
    /** @var list<string> prepared moves of the repertoire the file replaces (their suites go to the trash) */
    public array $replaced = [];
    /** Positions of the repertoire that go to the trash with the replaced moves. */
    public int $trashedPositions = 0;
}
