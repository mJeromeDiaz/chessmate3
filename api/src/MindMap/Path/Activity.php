<?php

declare(strict_types=1);

namespace App\MindMap\Path;

use App\Enum\Training\Module;

/**
 * What a step makes the player play (docs/MIND_MAP.md, § 4): a run of a training module with a
 * fixed configuration, and how that run is judged. An exercise passes with at least $count items
 * and {@see PathRules::PASS_RATE} of them right; a boss with at least $count items and fewer
 * failures than its lives: its items failed, or what its module counts as failures (the pieces
 * wrong or missing of a recall, `metrics.failures` of the run's summary).
 */
final readonly class Activity
{
    /** The run's subject is the player (coordinates, recall...). */
    public const SUBJECT_USER = 'user';

    /**
     * @param array<string, mixed> $config the run's, as POST /training/runs takes it
     */
    public function __construct(
        public Module $module,
        public array $config,
        public int $budgetSeconds,
        public int $count,
        public ?int $lives = null,
        public string $subject = self::SUBJECT_USER,
    ) {
    }

    /**
     * Whether a run that closed with these counts passes.
     *
     * @param int|null $failures the module's own count of failures, null: the items failed
     */
    public function passes(int $itemCount, int $successCount, ?int $failures = null): bool
    {
        if ($itemCount < $this->count || $itemCount <= 0) {
            return false;
        }
        if (null !== $this->lives) {
            return ($failures ?? $itemCount - $successCount) < $this->lives;
        }

        return $successCount / $itemCount >= PathRules::PASS_RATE;
    }
}
