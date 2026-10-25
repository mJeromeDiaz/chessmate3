<?php

declare(strict_types=1);

namespace App\MindMap\Progress;

use App\Entity\Training\Run;
use App\Enum\MindMap\AttemptOutcome;
use App\MindMap\Path\Paths;
use App\Repository\MindMap\AttemptRepository;
use App\Repository\MindMap\ProgressRepository;
use App\Training\Run\RunClosedListenerInterface;

/**
 * Judges a run played for a mind map step when it closes (docs/MIND_MAP.md, § 4), in the closing
 * transaction: a pass counts (the last one completes the step and rewards it), a failure counts
 * nothing, nor does a pass played with a help that makes it not count (the endgames' net switched
 * on, `metrics.netUsed`: assisted). A step already completed is practice: judged, never rewarded
 * again.
 */
final readonly class PassRecorder implements RunClosedListenerInterface
{
    public function __construct(
        private AttemptRepository $attempts,
        private ProgressRepository $progress,
        private Rewarder $rewarder,
    ) {
    }

    public function onRunClosed(Run $run, \DateTimeImmutable $now): void
    {
        $attempt = $this->attempts->findOfRun($run);
        if (null === $attempt || AttemptOutcome::Pending !== $attempt->getOutcome()) {
            return;
        }
        $found = Paths::find($attempt->getCapacity(), $attempt->getStep());
        $summary = $run->getSummary() ?? [];
        $itemCount = self::int($summary['itemCount'] ?? 0);
        $successCount = self::int($summary['successCount'] ?? 0);
        $activity = $found['step']->activity ?? null;
        $metrics = $summary['metrics'] ?? [];
        $failures = \is_array($metrics) && \is_int($metrics['failures'] ?? null) ? $metrics['failures'] : null;
        $passed = null !== $activity && $activity->passes($itemCount, $successCount, $failures);
        $assisted = $passed && \is_array($metrics) && \is_int($metrics['netUsed'] ?? null) && $metrics['netUsed'] > 0;
        $completed = false;
        if ($passed && !$assisted && null !== $found) {
            $user = $run->getUser();
            $this->progress->lockPathOf($user);
            $rows = $this->progress->byStep($user, $attempt->getCapacity());
            $step = $found['step'];
            $row = $rows[$step->key] ??= $this->rewarder->create($user, $attempt->getCapacity(), $step->key, $now);
            $completed = $row->pass($step->passes, $now);
            if ($completed) {
                $this->rewarder->completed($user, $attempt->getCapacity(), $step, $row, $rows, $run->getId()->toRfc4122(), $now);
            }
        }
        $attempt->judge($passed, $itemCount, $successCount, $completed, $now, $failures, $assisted);
    }

    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
