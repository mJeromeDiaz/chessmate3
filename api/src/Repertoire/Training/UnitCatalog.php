<?php

declare(strict_types=1);

namespace App\Repertoire\Training;

use App\Entity\Repertoire\Repertoire;
use App\Enum\Repertoire\PresentationStatus;
use App\Enum\Repertoire\TestUnit;
use App\Repertoire\Graph\GraphLoader;
use App\Repertoire\Srs\DueQuery;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Uid\Uuid;

/**
 * The units a repertoire test can present in its scope, with what the queue orders them by
 * ({@see UnitQueue}), and a unit by its key.
 */
final readonly class UnitCatalog
{
    public function __construct(
        private GraphLoader $loader,
        private DueQuery $due,
        private Connection $connection,
    ) {
    }

    /**
     * @param list<Repertoire> $repertoires
     *
     * @return list<UnitStanding>
     */
    public function standings(array $repertoires, Scope $scope, \DateTimeImmutable $now): array
    {
        $plans = [];
        foreach ($repertoires as $repertoire) {
            foreach ($this->plans($repertoire, $scope) as $plan) {
                $plans[] = [$repertoire->getId()->toRfc4122(), $plan];
            }
        }
        if ([] === $plans) {
            return [];
        }
        $ids = array_map(static fn (Repertoire $repertoire): Uuid => $repertoire->getId(), $repertoires);
        $overdue = $this->due->overdue($ids, $now);
        $history = $this->history($ids);

        return array_map(static function (array $entry) use ($overdue, $history): UnitStanding {
            [$repertoireId, $plan] = $entry;
            $dues = array_values(array_intersect_key($overdue, array_flip($plan->moveIds())));
            $segments = array_map(static fn (string $id): array => $history[$id] ?? ['count' => 0, 'failed' => false], $plan->segmentIds());
            $counts = array_map(static fn (array $segment): int => $segment['count'], $segments);

            return new UnitStanding(
                $repertoireId,
                $plan->key,
                [] === $dues ? null : min($dues),
                [] !== array_filter($segments, static fn (array $segment): bool => $segment['failed']),
                [] === $counts ? 0 : min($counts),
            );
        }, $plans);
    }

    /**
     * The unit keyed $key in this repertoire, if it is still presentable.
     */
    public function unit(Repertoire $repertoire, TestUnit $unit, string $key): ?UnitPlan
    {
        return $this->builder($repertoire)->unit($unit, $key);
    }

    /**
     * The repertoire's units, as its graph and its named positions are now.
     */
    public function builder(Repertoire $repertoire): UnitBuilder
    {
        $openings = [];
        $rows = $this->connection->fetchAllAssociative(
            'SELECT p.id, o.eco, o.name FROM repertoire_position p JOIN repertoire_opening o ON o.epd_hash = p.fen_hash WHERE p.repertoire_id = ?',
            [$repertoire->getId()->toBinary()],
            [ParameterType::BINARY],
        );
        foreach ($rows as $row) {
            if (\is_string($row['id']) && \is_string($row['eco']) && \is_string($row['name'])) {
                $openings[Uuid::fromBinary($row['id'])->toRfc4122()] = ['eco' => $row['eco'], 'name' => $row['name']];
            }
        }

        return new UnitBuilder($this->loader->load($repertoire), $openings);
    }

    /**
     * @return list<UnitPlan>
     */
    private function plans(Repertoire $repertoire, Scope $scope): array
    {
        $builder = $this->builder($repertoire);
        $only = null;
        if (null !== $scope->rootPositionId) {
            $only = $builder->segmentsUnder($scope->rootPositionId);
        }
        if (null !== $scope->segmentIds) {
            $only = null === $only ? $scope->segmentIds : array_intersect_key($only, $scope->segmentIds);
        }

        return $builder->units($scope->unit, $only);
    }

    /**
     * Finished presentations by segment: how many tests (rank 1 in a run, as in the statistics: a
     * retry is not one more test), and whether the last one, retries included, failed.
     *
     * @param list<Uuid> $repertoireIds
     *
     * @return array<string, array{count: int, failed: bool}>
     */
    private function history(array $repertoireIds): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT segment_id, n, status FROM (
                SELECT segment_id, status, SUM(presentation_rank = 1) OVER (PARTITION BY segment_id) n,
                       ROW_NUMBER() OVER (PARTITION BY segment_id ORDER BY finished_at DESC, id DESC) latest
                FROM repertoire_presentation WHERE repertoire_id IN (?) AND status IN (?)
             ) p WHERE latest = 1',
            [
                array_map(static fn (Uuid $id): string => $id->toBinary(), $repertoireIds),
                [PresentationStatus::Succeeded->value, PresentationStatus::Failed->value],
            ],
            [ArrayParameterType::BINARY, ArrayParameterType::STRING],
        );
        $history = [];
        foreach ($rows as $row) {
            if (!\is_string($row['segment_id']) || !is_numeric($row['n'])) {
                throw new \UnexpectedValueException('Bad presentation row.');
            }
            $history[Uuid::fromBinary($row['segment_id'])->toRfc4122()] = ['count' => (int) $row['n'], 'failed' => PresentationStatus::Failed->value === $row['status']];
        }

        return $history;
    }
}
