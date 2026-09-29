<?php

declare(strict_types=1);

namespace App\Training\Module;

/**
 * The normalized recap of a run, the same for every module (docs/TRAINING.md): real duration,
 * items resolved, successes, plus module-specific metrics.
 */
final readonly class Summary
{
    /**
     * @param array<string, mixed> $metrics
     */
    public function __construct(
        public int $durationMs,
        public int $itemCount,
        public int $successCount,
        public array $metrics = [],
    ) {
    }

    /**
     * @param array<string, mixed> $context why the run closed, when the module explains it
     *
     * @return array{durationMs: int, itemCount: int, successCount: int, failureCount: int, successRate: float|null, itemsPerMinute: float|null, metrics: array<string, mixed>, context: array<string, mixed>}
     */
    public function toArray(array $context = []): array
    {
        return [
            'durationMs' => $this->durationMs,
            'itemCount' => $this->itemCount,
            'successCount' => $this->successCount,
            'failureCount' => $this->itemCount - $this->successCount,
            'successRate' => $this->itemCount > 0 ? round($this->successCount / $this->itemCount, 4) : null,
            'itemsPerMinute' => $this->durationMs > 0 ? round($this->itemCount * 60_000 / $this->durationMs, 2) : null,
            'metrics' => $this->metrics,
            'context' => $context,
        ];
    }
}
