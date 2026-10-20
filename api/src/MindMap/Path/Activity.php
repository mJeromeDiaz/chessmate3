<?php

declare(strict_types=1);

namespace App\MindMap\Path;

use App\Enum\Training\Module;

/**
 * What a step makes the player play (docs/MIND_MAP.md, § 4): a run of a training module with a
 * fixed configuration, and how that run is judged. An exercise passes with at least $count items
 * and {@see PathRules::PASS_RATE} of them right; a boss with at least $count items and fewer
 * failures than its lives.
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
     */
    public function passes(int $itemCount, int $successCount): bool
    {
        if ($itemCount < $this->count || $itemCount <= 0) {
            return false;
        }
        if (null !== $this->lives) {
            return $itemCount - $successCount < $this->lives;
        }

        return $successCount / $itemCount >= PathRules::PASS_RATE;
    }
}
