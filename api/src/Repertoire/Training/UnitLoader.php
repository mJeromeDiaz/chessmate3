<?php

declare(strict_types=1);

namespace App\Repertoire\Training;

use App\Enum\Repertoire\MoveRole;
use App\Repertoire\Training\State\Question;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * Reads what a unit shows ({@see UnitPlan}), as it is now: its context and its questions, each
 * with the opponent's moves played before it.
 */
final readonly class UnitLoader
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * @return array{
     *     context: list<array{uci: string, san: string}>,
     *     questions: list<Question>,
     *     sans: array<string, list<string>>
     * }
     */
    public function load(UnitPlan $plan): array
    {
        $rows = $this->rows(array_merge($plan->contextMoveIds, $plan->moveIds()));
        $context = [];
        foreach ($plan->contextMoveIds as $id) {
            $row = $rows[$id] ?? throw new \UnexpectedValueException('No move '.$id);
            $context[] = ['uci' => $row['uci'], 'san' => $row['san']];
        }

        $questions = [];
        $sans = [];
        $play = [];
        foreach ($plan->segments as $segment) {
            foreach ($segment['moveIds'] as $id) {
                $row = $rows[$id] ?? throw new \UnexpectedValueException('No move '.$id);
                $sans[$segment['segmentId']][] = $row['san'];
                if (MoveRole::Reply->value === $row['role']) {
                    $play[] = ['uci' => $row['uci'], 'san' => $row['san']];
                    continue;
                }
                $questions[] = new Question($segment['segmentId'], $row['from'], $row['fen'], $row['ply'], $row['uci'], $row['san'], $row['comment'], $play);
                $play = [];
            }
        }

        return [
            'context' => $context,
            'questions' => $questions,
            'sans' => $sans,
        ];
    }

    /**
     * @param list<string> $ids
     *
     * @return array<string, array{uci: string, san: string, role: string, comment: string|null, from: string, fen: string, ply: int}>
     */
    private function rows(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }
        $rows = $this->connection->fetchAllAssociative(
            'SELECT m.id, m.uci, m.san, m.role, m.comment, m.from_position_id, f.fen, f.depth
             FROM repertoire_move m
             JOIN repertoire_position f ON f.id = m.from_position_id
             WHERE m.id IN (?)',
            [array_map(static fn (string $id): string => Uuid::fromString($id)->toBinary(), $ids)],
            [ArrayParameterType::BINARY],
        );
        $out = [];
        foreach ($rows as $row) {
            $string = static fn (string $key): string => \is_string($row[$key]) ? $row[$key] : throw new \UnexpectedValueException('No '.$key);
            $out[Uuid::fromBinary($string('id'))->toRfc4122()] = [
                'uci' => $string('uci'),
                'san' => $string('san'),
                'role' => $string('role'),
                'comment' => \is_string($row['comment']) && '' !== $row['comment'] ? $row['comment'] : null,
                'from' => Uuid::fromBinary($string('from_position_id'))->toRfc4122(),
                'fen' => $string('fen'),
                'ply' => is_numeric($row['depth']) ? (int) $row['depth'] : throw new \UnexpectedValueException('No depth'),
            ];
        }

        return $out;
    }
}
