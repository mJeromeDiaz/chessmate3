<?php

declare(strict_types=1);

namespace App\Repertoire\Stats;

use App\Enum\Repertoire\PresentationStatus;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Uid\Uuid;

/**
 * Aggregates of the tests of segments (docs/REPERTOIRE.md § 15). A test is the first presentation
 * of a segment in a run (rank 1), succeeded or failed: coming back after a mistake is not a test.
 */
final readonly class TestHistory
{
    /** A segment is fragile over its last tests. */
    public const RECENT = 10;

    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * By segment (RFC 4122), archived ones included.
     *
     * @return array<string, SegmentTests>
     */
    public function bySegment(Uuid $repertoireId, \DateTimeImmutable $now): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT segment_id, COUNT(*) n, SUM(status = ?) ok, SUM(finished_at >= ?) n7, SUM(finished_at >= ?) n30, MAX(finished_at) last_at,
                    SUM(latest <= ?) recent, SUM(latest <= ? AND status = ?) recent_failed, MAX(CASE WHEN latest = 1 THEN status END) last_status
             FROM (
                SELECT segment_id, status, finished_at, ROW_NUMBER() OVER (PARTITION BY segment_id ORDER BY finished_at DESC, id DESC) latest
                FROM repertoire_presentation WHERE repertoire_id = ? AND presentation_rank = 1 AND status IN (?)
             ) p GROUP BY segment_id',
            [
                PresentationStatus::Succeeded->value, self::instant($now->modify('-7 days')), self::instant($now->modify('-30 days')),
                self::RECENT, self::RECENT, PresentationStatus::Failed->value, $repertoireId->toBinary(), self::finished(),
            ],
            [
                ParameterType::STRING, ParameterType::STRING, ParameterType::STRING,
                ParameterType::INTEGER, ParameterType::INTEGER, ParameterType::STRING, ParameterType::BINARY, ArrayParameterType::STRING,
            ],
        );
        $tests = [];
        foreach ($rows as $row) {
            $tests[Uuid::fromBinary(self::string($row['segment_id']))->toRfc4122()] = new SegmentTests(
                self::int($row['n']),
                self::int($row['ok']),
                self::int($row['n7']),
                self::int($row['n30']),
                new \DateTimeImmutable(self::string($row['last_at'])),
                PresentationStatus::from(self::string($row['last_status'])),
                self::int($row['recent']),
                self::int($row['recent_failed']),
            );
        }

        return $tests;
    }

    /**
     * By repertoire (RFC 4122): tests, those of the last 30 days and how many succeeded, the last one.
     *
     * @param list<Uuid> $repertoireIds
     *
     * @return array<string, array{tests: int, last30: int, succeeded30: int, lastAt: \DateTimeImmutable}>
     */
    public function byRepertoire(array $repertoireIds, \DateTimeImmutable $now): array
    {
        if ([] === $repertoireIds) {
            return [];
        }
        $since = self::instant($now->modify('-30 days'));
        $rows = $this->connection->fetchAllAssociative(
            'SELECT repertoire_id, COUNT(*) n, SUM(finished_at >= ?) n30, SUM(finished_at >= ? AND status = ?) ok30, MAX(finished_at) last_at
             FROM repertoire_presentation WHERE repertoire_id IN (?) AND presentation_rank = 1 AND status IN (?)
             GROUP BY repertoire_id',
            [$since, $since, PresentationStatus::Succeeded->value, array_map(static fn (Uuid $id): string => $id->toBinary(), $repertoireIds), self::finished()],
            [ParameterType::STRING, ParameterType::STRING, ParameterType::STRING, ArrayParameterType::BINARY, ArrayParameterType::STRING],
        );
        $out = [];
        foreach ($rows as $row) {
            $out[Uuid::fromBinary(self::string($row['repertoire_id']))->toRfc4122()] = [
                'tests' => self::int($row['n']),
                'last30' => self::int($row['n30']),
                'succeeded30' => self::int($row['ok30']),
                'lastAt' => new \DateTimeImmutable(self::string($row['last_at'])),
            ];
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private static function finished(): array
    {
        return [PresentationStatus::Succeeded->value, PresentationStatus::Failed->value];
    }

    private static function instant(\DateTimeImmutable $at): string
    {
        return $at->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : throw new \UnexpectedValueException('Not a number.');
    }

    private static function string(mixed $value): string
    {
        return \is_string($value) ? $value : throw new \UnexpectedValueException('Not a string.');
    }
}
