<?php

declare(strict_types=1);

namespace App\Dashboard\Repertoire;

use App\Dashboard\Period;
use App\Entity\Repertoire\Repertoire;
use App\Entity\User;
use App\Enum\Repertoire\PresentationStatus;
use App\Repertoire\Srs\DueQuery;
use App\Repertoire\Stats\StatsReader;
use App\Repertoire\Stats\TestHistory;
use App\Repository\Repertoire\RepertoireRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * The health of the user's repertoires, all of them together (docs/DASHBOARD.md): active cards
 * (new, due now), the tests of the period (a test is the first presentation of a segment in a run,
 * docs/REPERTOIRE.md § 15) and the most fragile segments, by the rule of the repertoire statistics
 * (at least {@see StatsReader::FRAGILE_MIN_TESTS} tests, failures among the last
 * {@see TestHistory::RECENT}), on segments still active (neither archived nor merged). A segment
 * is labelled as at its last test.
 *
 * @phpstan-type Label array{opening: array{eco: string, name: string}|null, move: string|null}
 * @phpstan-type Fragile array{repertoireId: string, repertoireName: string, color: string, segmentId: string, label: Label|null, tests: int, recentFailureRate: float}
 * @phpstan-type Health array{repertoires: int, cards: array{total: int, new: int, due: int}, tests: array{total: int, succeeded: int, successRate: float|null}, fragile: list<Fragile>}
 */
final class RepertoireHealth
{
    public const FRAGILE_LIMIT = 5;

    public function __construct(
        private readonly RepertoireRepository $repertoires,
        private readonly DueQuery $due,
        private readonly Connection $connection,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @return Health
     */
    public function compute(User $user, Period $period): array
    {
        $repertoires = [];
        foreach ($this->repertoires->findByUser($user) as $repertoire) {
            $repertoires[$repertoire->getId()->toRfc4122()] = $repertoire;
        }
        $ids = array_values(array_map(static fn (Repertoire $repertoire): Uuid => $repertoire->getId(), $repertoires));
        $finished = [PresentationStatus::Succeeded->value, PresentationStatus::Failed->value];

        $tests = $this->connection->fetchAssociative(
            'SELECT COUNT(*) AS n, SUM(status = :succeeded) AS ok
               FROM repertoire_presentation
              WHERE user_id = :user AND finished_at >= :since AND presentation_rank = 1 AND status IN (:finished)',
            ['user' => $user->getId()->toBinary(), 'since' => $period->since->format('Y-m-d H:i:s'), 'succeeded' => PresentationStatus::Succeeded->value, 'finished' => $finished],
            ['finished' => ArrayParameterType::STRING],
        ) ?: [];
        $total = self::int($tests['n'] ?? null);
        $succeeded = self::int($tests['ok'] ?? null);

        return [
            'repertoires' => \count($repertoires),
            'cards' => $this->due->counts($ids, $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))),
            'tests' => ['total' => $total, 'succeeded' => $succeeded, 'successRate' => $total > 0 ? round($succeeded / $total, 4) : null],
            'fragile' => [] === $ids ? [] : $this->fragile($user, $repertoires, $finished),
        ];
    }

    /**
     * @param array<string, Repertoire> $repertoires by id (RFC 4122)
     * @param list<string>              $finished
     *
     * @return list<Fragile>
     */
    private function fragile(User $user, array $repertoires, array $finished): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT p.segment_id, p.repertoire_id, COUNT(*) AS n, SUM(p.latest <= :recent) AS recent, SUM(p.latest <= :recent AND p.status = :failed) AS recent_failed,
                    SUM(p.latest <= :recent AND p.status = :failed) / SUM(p.latest <= :recent) AS failure_rate
               FROM (
                    SELECT segment_id, repertoire_id, status,
                           ROW_NUMBER() OVER (PARTITION BY segment_id ORDER BY finished_at DESC, id DESC) AS latest
                      FROM repertoire_presentation
                     WHERE user_id = :user AND presentation_rank = 1 AND status IN (:finished)
               ) p
               JOIN repertoire_segment s ON s.id = p.segment_id AND s.archived_at IS NULL AND s.merged_into_segment_id IS NULL
              GROUP BY p.segment_id, p.repertoire_id
             HAVING n >= :min AND recent_failed > 0
              ORDER BY failure_rate DESC, n DESC, p.segment_id
              LIMIT :limit',
            [
                'user' => $user->getId()->toBinary(), 'finished' => $finished, 'failed' => PresentationStatus::Failed->value,
                'recent' => TestHistory::RECENT, 'min' => StatsReader::FRAGILE_MIN_TESTS, 'limit' => self::FRAGILE_LIMIT,
            ],
            [
                'finished' => ArrayParameterType::STRING, 'recent' => ParameterType::INTEGER,
                'min' => ParameterType::INTEGER, 'limit' => ParameterType::INTEGER,
            ],
        );
        if ([] === $rows) {
            return [];
        }
        $labels = $this->labels(array_map(static fn (array $row): string => self::string($row['segment_id']), $rows), $finished);

        $fragile = [];
        foreach ($rows as $row) {
            $repertoire = $repertoires[Uuid::fromBinary(self::string($row['repertoire_id']))->toRfc4122()] ?? null;
            if (null === $repertoire) {
                continue;
            }
            $segmentId = self::string($row['segment_id']);
            $recent = self::int($row['recent']);
            $fragile[] = [
                'repertoireId' => $repertoire->getId()->toRfc4122(),
                'repertoireName' => $repertoire->getName(),
                'color' => $repertoire->getColor()->value,
                'segmentId' => Uuid::fromBinary($segmentId)->toRfc4122(),
                'label' => $labels[$segmentId] ?? null,
                'tests' => self::int($row['n']),
                'recentFailureRate' => $recent > 0 ? round(self::int($row['recent_failed']) / $recent, 4) : 0.0,
            ];
        }

        return $fragile;
    }

    /**
     * The label of each segment at its last test.
     *
     * @param list<string> $segmentIds binary
     * @param list<string> $finished
     *
     * @return array<string, Label> by binary segment id
     */
    private function labels(array $segmentIds, array $finished): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT segment_id, label FROM (
                    SELECT segment_id, label, ROW_NUMBER() OVER (PARTITION BY segment_id ORDER BY finished_at DESC, id DESC) AS latest
                      FROM repertoire_presentation
                     WHERE segment_id IN (:segments) AND presentation_rank = 1 AND status IN (:finished)
               ) p WHERE latest = 1',
            ['segments' => $segmentIds, 'finished' => $finished],
            ['segments' => ArrayParameterType::BINARY, 'finished' => ArrayParameterType::STRING],
        );
        $labels = [];
        foreach ($rows as $row) {
            $label = json_decode(self::string($row['label']), true, flags: \JSON_THROW_ON_ERROR);
            if (\is_array($label)) {
                /** @var Label $label */
                $labels[self::string($row['segment_id'])] = $label;
            }
        }

        return $labels;
    }

    /** COUNT and SUM come back as numeric strings (SUM of no row: null). */
    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private static function string(mixed $value): string
    {
        return \is_string($value) ? $value : throw new \UnexpectedValueException('Not a string.');
    }
}
