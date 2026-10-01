<?php

declare(strict_types=1);

namespace App\Repertoire\Stats;

use App\ApiResource\Repertoire\Overview;
use App\ApiResource\Repertoire\RunReport;
use App\ApiResource\Repertoire\SegmentHistory;
use App\ApiResource\Repertoire\Stats;
use App\Entity\Repertoire\Presentation;
use App\Entity\Repertoire\Repertoire;
use App\Entity\User;
use App\Enum\Repertoire\PresentationStatus;
use App\Enum\Training\Module;
use App\Repertoire\Srs\DueQuery;
use App\Repertoire\Training\UnitCatalog;
use App\Repository\Repertoire\PresentationRepository;
use App\Repository\Repertoire\RepertoireRepository;
use App\Repository\Repertoire\SegmentRepository;
use App\Repository\Training\RunRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * The statistics of the repertoire test (docs/REPERTOIRE.md § 15), for the API. The user's data
 * only: another user's repertoire, segment or run reads as absent.
 *
 * @phpstan-import-type SegmentStats from Stats
 * @phpstan-import-type PresentationRow from SegmentHistory
 */
final readonly class StatsReader
{
    public const HISTORY_LIMIT = 50;
    public const FRAGILE_MIN_TESTS = 3;
    public const FRAGILE_LIMIT = 10;

    private const NO_CARDS = ['total' => 0, 'new' => 0, 'learning' => 0, 'review' => 0, 'due' => 0];

    public function __construct(
        private RepertoireRepository $repertoires,
        private SegmentRepository $segments,
        private PresentationRepository $presentations,
        private RunRepository $runs,
        private DueQuery $due,
        private TestHistory $tests,
        private UnitCatalog $catalog,
        private Connection $connection,
        private ClockInterface $clock,
    ) {
    }

    public function overview(User $user): Overview
    {
        $now = $this->now();
        $repertoires = $this->repertoires->findByUser($user);
        $ids = array_map(static fn (Repertoire $repertoire): Uuid => $repertoire->getId(), $repertoires);
        $cards = $this->due->countsByRepertoire($ids, $now);
        $tests = $this->tests->byRepertoire($ids, $now);

        $overview = new Overview();
        $overview->cards = self::NO_CARDS;
        foreach ($repertoires as $repertoire) {
            $id = $repertoire->getId()->toRfc4122();
            $counts = $cards[$id] ?? self::NO_CARDS;
            foreach ($counts as $name => $count) {
                $overview->cards[$name] += $count;
            }
            $test = $tests[$id] ?? null;
            $overview->repertoires[] = [
                'id' => $id,
                'name' => $repertoire->getName(),
                'color' => $repertoire->getColor()->value,
                'cards' => $counts,
                'tests' => $test['tests'] ?? 0,
                'successRate30' => null === $test || 0 === $test['last30'] ? null : round($test['succeeded30'] / $test['last30'], 4),
                'lastTestedAt' => null === $test ? null : $test['lastAt']->format(\DATE_ATOM),
            ];
        }
        $zone = $user->getDateTimeZone();
        $overview->forecast = Forecast::days($this->due->dueDates($ids, Forecast::until($now, $zone)), $now, $zone);

        return $overview;
    }

    public function repertoire(User $user, Uuid $id): ?Stats
    {
        $repertoire = $this->repertoires->findOwned($id, $user);
        if (null === $repertoire) {
            return null;
        }
        $now = $this->now();
        $zone = $user->getDateTimeZone();
        $builder = $this->catalog->builder($repertoire);
        $tests = $this->tests->bySegment($id, $now);
        $cards = $this->due->countsBySegment($id, $now);
        $active = [];
        foreach ($this->segments->findActiveOf($repertoire) as $segment) {
            $active[$segment->getId()->toRfc4122()] = $segment;
        }

        $stats = new Stats();
        $stats->id = $id->toRfc4122();
        $stats->name = $repertoire->getName();
        $stats->color = $repertoire->getColor()->value;
        $stats->cards = $this->due->countsByRepertoire([$id], $now)[$stats->id] ?? self::NO_CARDS;
        $stats->forecast = Forecast::days($this->due->dueDates([$id], Forecast::until($now, $zone)), $now, $zone);

        $total = $succeeded = $last7 = $last30 = 0;
        $lastAt = null;
        foreach ($tests as $test) {
            $total += $test->total;
            $succeeded += $test->succeeded;
            $last7 += $test->last7;
            $last30 += $test->last30;
            $lastAt = null === $lastAt || $test->lastAt > $lastAt ? $test->lastAt : $lastAt;
        }
        $stats->tests = [
            'total' => $total,
            'succeeded' => $succeeded,
            'failed' => $total - $succeeded,
            'successRate' => $total > 0 ? round($succeeded / $total, 4) : null,
            'last7' => $last7,
            'last30' => $last30,
            'lastAt' => $lastAt?->format(\DATE_ATOM),
        ];

        foreach ($builder->presentable() as $segmentId) {
            $segment = $active[$segmentId] ?? null;
            $test = $tests[$segmentId] ?? null;
            $stats->segments[] = [
                'id' => $segmentId,
                'label' => $builder->label($segmentId),
                'path' => $builder->path($segmentId),
                'userMoveCount' => $segment?->getUserMoveCount() ?? 0,
                'derivedFromSegmentId' => $segment?->getDerivedFromSegmentId()?->toRfc4122(),
                'cards' => $cards[$segmentId] ?? ['new' => 0, 'due' => 0],
                'tests' => [
                    'total' => $test->total ?? 0,
                    'succeeded' => $test->succeeded ?? 0,
                    'successRate' => $test?->successRate(),
                    'last7' => $test->last7 ?? 0,
                    'last30' => $test->last30 ?? 0,
                    'lastAt' => $test?->lastAt->format(\DATE_ATOM),
                    'lastStatus' => $test?->lastStatus->value,
                    'recent' => $test->recent ?? 0,
                    'recentFailureRate' => $test?->recentFailureRate(),
                ],
            ];
        }

        $fragile = array_values(array_filter(
            $stats->segments,
            static fn (array $segment): bool => $segment['tests']['total'] >= self::FRAGILE_MIN_TESTS && ($segment['tests']['recentFailureRate'] ?? 0.0) > 0.0,
        ));
        usort($fragile, static fn (array $a, array $b): int => [$b['tests']['recentFailureRate'], $b['tests']['total']] <=> [$a['tests']['recentFailureRate'], $a['tests']['total']]);
        $stats->fragile = \array_slice($fragile, 0, self::FRAGILE_LIMIT);

        return $stats;
    }

    public function segment(User $user, Uuid $repertoireId, Uuid $segmentId): ?SegmentHistory
    {
        $repertoire = $this->repertoires->findOwned($repertoireId, $user);
        $segment = null === $repertoire ? null : $this->segments->findInRepertoire($segmentId, $repertoire);
        if (null === $repertoire || null === $segment) {
            return null;
        }
        $merged = $this->mergedInto($segmentId);
        $builder = $this->catalog->builder($repertoire);
        $presentable = \in_array($segmentId->toRfc4122(), $builder->presentable(), true);

        $history = new SegmentHistory();
        $history->id = $segmentId->toRfc4122();
        $history->repertoireId = $repertoireId->toRfc4122();
        $history->label = $presentable ? $builder->label($history->id) : null;
        $history->path = $presentable ? $builder->path($history->id) : [];
        $history->archived = $segment->isArchived();
        $history->derivedFromSegmentId = $segment->getDerivedFromSegmentId()?->toRfc4122();
        $history->mergedIntoSegmentId = $segment->getMergedIntoSegmentId()?->toRfc4122();
        $history->presentations = array_map(
            static fn (Presentation $presentation): array => self::row($presentation, $presentation->getSegment()->getId()->toRfc4122() !== $history->id),
            $this->presentations->findFinishedOf([$segmentId, ...$merged], self::HISTORY_LIMIT),
        );

        return $history;
    }

    public function run(User $user, Uuid $runId): ?RunReport
    {
        $run = $this->runs->findOwned($runId, $user);
        if (null === $run || Module::Repertoire !== $run->getModule()) {
            return null;
        }
        $report = new RunReport();
        $report->id = $runId->toRfc4122();
        $report->status = $run->getStatus()->value;
        $units = [];
        foreach ($this->presentations->findByRun($run) as $presentation) {
            $unitId = $presentation->getUnitId()->toRfc4122();
            $units[$unitId] ??= [
                'unitId' => $unitId,
                'unit' => $presentation->getUnit()->value,
                'rank' => $presentation->getRank(),
                'round' => $presentation->getRound(),
                'status' => PresentationStatus::Succeeded->value,
                'label' => $presentation->getLabel(),
                'repertoireId' => $presentation->getRepertoire()->getId()->toRfc4122(),
                'segments' => [],
            ];
            // A line is labelled by its last segment.
            $units[$unitId]['label'] = $presentation->getLabel();
            $units[$unitId]['segments'][] = [
                'segmentId' => $presentation->getSegment()->getId()->toRfc4122(),
                'label' => $presentation->getLabel(),
                'status' => $presentation->getStatus()->value,
                'firstErrorPly' => $presentation->getFirstErrorPly(),
                'positionsGraded' => $presentation->getPositionsGraded(),
                'durationMs' => $presentation->getDurationMs(),
                'moves' => $presentation->getMoves(),
            ];
        }
        foreach ($units as $unitId => $unit) {
            $statuses = array_column($unit['segments'], 'status');
            $units[$unitId]['status'] = match (true) {
                \in_array(PresentationStatus::InProgress->value, $statuses, true) => PresentationStatus::InProgress->value,
                \in_array(PresentationStatus::Failed->value, $statuses, true) => PresentationStatus::Failed->value,
                [PresentationStatus::Succeeded->value] === array_values(array_unique($statuses)) => PresentationStatus::Succeeded->value,
                default => PresentationStatus::Interrupted->value,
            };
        }
        $report->units = array_values($units);

        return $report;
    }

    /**
     * The segments merged into this one, directly or through others.
     *
     * @return list<Uuid>
     */
    private function mergedInto(Uuid $segmentId): array
    {
        $found = [];
        $frontier = [$segmentId->toBinary()];
        for ($depth = 0; [] !== $frontier && $depth < 50; ++$depth) {
            $frontier = array_values(array_filter(
                $this->connection->fetchFirstColumn('SELECT id FROM repertoire_segment WHERE merged_into_segment_id IN (?)', [$frontier], [ArrayParameterType::BINARY]),
                'is_string',
            ));
            foreach ($frontier as $id) {
                $found[] = Uuid::fromBinary($id);
            }
        }

        return $found;
    }

    /**
     * @return PresentationRow
     */
    private static function row(Presentation $presentation, bool $beforeMerge): array
    {
        return [
            'id' => $presentation->getId()->toRfc4122(),
            'segmentId' => $presentation->getSegment()->getId()->toRfc4122(),
            'beforeMerge' => $beforeMerge,
            'runId' => $presentation->getRun()?->getId()->toRfc4122(),
            'unit' => $presentation->getUnit()->value,
            'rank' => $presentation->getRank(),
            'round' => $presentation->getRound(),
            'status' => $presentation->getStatus()->value,
            'firstErrorPly' => $presentation->getFirstErrorPly(),
            'positionsGraded' => $presentation->getPositionsGraded(),
            'durationMs' => $presentation->getDurationMs(),
            'moves' => $presentation->getMoves(),
            'label' => $presentation->getLabel(),
            'startedAt' => $presentation->getStartedAt()->format(\DATE_ATOM),
            'finishedAt' => $presentation->getFinishedAt()?->format(\DATE_ATOM),
        ];
    }

    private function now(): \DateTimeImmutable
    {
        return $this->clock->now()->setTimezone(new \DateTimeZone('UTC'));
    }
}
