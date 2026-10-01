<?php

declare(strict_types=1);

namespace App\Enum\Repertoire;

/**
 * Why a suite went to the trash (docs/REPERTOIRE.md, "Corbeille"). Stored as its value: add cases,
 * never rename one.
 */
enum TrashReason: string
{
    /** Its first move was replaced by another prepared move (editor, restore). */
    case Replaced = 'replaced';
    /** Deleted in the editor. */
    case Deleted = 'deleted';
    /** An import chose the file's move instead. */
    case Imported = 'imported';
    /** Set aside when a position could still hold several prepared moves (before 2026-09-30). */
    case Migrated = 'migrated';
}
