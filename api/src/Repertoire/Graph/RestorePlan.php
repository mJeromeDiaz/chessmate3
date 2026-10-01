<?php

declare(strict_types=1);

namespace App\Repertoire\Graph;

/**
 * What restoring a trashed suite does ({@see RestorePlanner}): what the preview shows, and what
 * {@see GraphEditor::restore()} writes.
 *
 * @phpstan-type RestoreConflict array{fen: string, path: list<string>, restored: array{uci: string, san: string}, current: array{uci: string, san: string}, choice: 'restored'|'current'}
 */
final class RestorePlan
{
    /** @var list<RestoreConflict> positions where the suite and the repertoire prepare different moves */
    public array $conflicts = [];
    /** @var list<array<string, mixed>> position rows to insert, with their ids */
    public array $positions = [];
    /** @var list<array<string, mixed>> move rows to insert, with their ids, from and to remapped */
    public array $moves = [];
    /** @var list<string> current moves replaced by the suite's (their own suites go to the trash) */
    public array $replaced = [];
    /** Moves of the suite already in the repertoire (joined, not inserted). */
    public int $joined = 0;
    /** Moves of the suite left out: the current move was kept, or they would close a cycle. */
    public int $leftOut = 0;
}
