<?php

declare(strict_types=1);

namespace App\MindMap\Progress;

use App\Entity\MindMap\Progress;

/**
 * The progress rows as {@see \App\MindMap\Path\PathState} reads them.
 *
 * @phpstan-import-type StepProgress from \App\MindMap\Path\PathState
 */
final class Progresses
{
    /**
     * @param array<string, Progress> $rows by step key
     *
     * @return array<string, StepProgress>
     */
    public static function state(array $rows): array
    {
        return array_map(static fn (Progress $row): array => [
            'passes' => $row->getPasses(),
            'done' => null !== $row->getCompletedAt(),
            'skipped' => null !== $row->getSkippedAt(),
        ], $rows);
    }
}
