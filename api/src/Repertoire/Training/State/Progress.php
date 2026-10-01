<?php

declare(strict_types=1);

namespace App\Repertoire\Training\State;

use App\Repertoire\Training\Scope;

/**
 * Where a repertoire test stands: its scope, the queue of its round, the unit being played and
 * the run's counters. Stored in repertoire_run_state.
 */
final class Progress
{
    /** @var list<array{repertoireId: string, key: string, retry: bool}> */
    public array $queue = [];
    public int $round = 1;
    public ?string $last = null;
    /** @var array<string, int> unit key => presentations in this run */
    public array $presented = [];
    /** @var array<string, int> unit key => 1 when it failed in this run */
    public array $failed = [];
    public ?Unit $current = null;
    /** @var array<string, int> succeeded, failed, recovered, interrupted, dropped, positionsGraded */
    public array $counters = ['succeeded' => 0, 'failed' => 0, 'recovered' => 0, 'interrupted' => 0, 'dropped' => 0, 'positionsGraded' => 0];

    public function __construct(
        public readonly Scope $scope,
    ) {
    }

    public function count(string $counter, int $by = 1): void
    {
        $this->counters[$counter] = ($this->counters[$counter] ?? 0) + $by;
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $progress = new self(Scope::fromConfig(Read::array($data, 'scope')));
        $progress->queue = array_map(static fn (array $entry): array => [
            'repertoireId' => Read::string($entry, 'repertoireId'),
            'key' => Read::string($entry, 'key'),
            'retry' => Read::bool($entry, 'retry'),
        ], Read::arrays($data, 'queue'));
        $progress->round = Read::int($data, 'round');
        $progress->last = Read::nullableString($data, 'last');
        $progress->presented = Read::counts($data, 'presented');
        $progress->failed = Read::counts($data, 'failed');
        $progress->current = null === ($data['current'] ?? null) ? null : Unit::fromArray(Read::array($data, 'current'));
        $progress->counters = Read::counts($data, 'counters');

        return $progress;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'scope' => array_filter($this->scope->toArray(), static fn (mixed $value): bool => null !== $value),
            'queue' => $this->queue,
            'round' => $this->round,
            'last' => $this->last,
            // Empty maps stay JSON objects.
            'presented' => (object) $this->presented,
            'failed' => (object) $this->failed,
            'current' => $this->current?->toArray(),
            'counters' => $this->counters,
        ];
    }
}
