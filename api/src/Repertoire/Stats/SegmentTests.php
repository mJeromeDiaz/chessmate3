<?php

declare(strict_types=1);

namespace App\Repertoire\Stats;

use App\Enum\Repertoire\PresentationStatus;

/**
 * The tests of one segment ({@see TestHistory}).
 */
final readonly class SegmentTests
{
    public function __construct(
        public int $total,
        public int $succeeded,
        public int $last7,
        public int $last30,
        public \DateTimeImmutable $lastAt,
        public PresentationStatus $lastStatus,
        /** Among the last {@see TestHistory::RECENT} tests. */
        public int $recent,
        public int $recentFailed,
    ) {
    }

    public function successRate(): ?float
    {
        return $this->total > 0 ? round($this->succeeded / $this->total, 4) : null;
    }

    public function recentFailureRate(): ?float
    {
        return $this->recent > 0 ? round($this->recentFailed / $this->recent, 4) : null;
    }
}
