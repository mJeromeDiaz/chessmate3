<?php

declare(strict_types=1);

namespace App\Repertoire\Import;

use App\Chess\Rules;
use App\Entity\Repertoire\Repertoire;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Uid\Uuid;

/**
 * What an import is planned against: the positions and moves of the repertoire it goes into,
 * keyed by normalized FEN (a new repertoire has its initial position only).
 *
 * @phpstan-type TargetMove array{id: string, from: string, to: string, uci: string, san: string, role: string, sortOrder: int, comment: string|null, nags: list<int>}
 */
final readonly class Target
{
    /**
     * @param array<string, string> $positions normalized FEN => position id ('' for the initial
     *                                         position of a repertoire still to create)
     * @param list<TargetMove>      $moves     from/to are normalized FENs
     */
    public function __construct(
        public array $positions,
        public array $moves,
    ) {
    }

    /** A repertoire to create: nothing but its initial position. */
    public static function empty(): self
    {
        return new self([Rules::initial()->normalizedFen() => ''], []);
    }

    public static function load(Connection $connection, Repertoire $repertoire): self
    {
        $id = [$repertoire->getId()->toBinary()];
        $types = [ParameterType::BINARY];
        $positions = [];
        $fens = [];
        foreach ($connection->iterateAssociative('SELECT id, fen FROM repertoire_position WHERE repertoire_id = ?', $id, $types) as $row) {
            $uuid = Uuid::fromBinary(self::string($row['id']))->toRfc4122();
            $positions[self::string($row['fen'])] = $uuid;
            $fens[$uuid] = self::string($row['fen']);
        }
        $moves = [];
        $rows = $connection->iterateAssociative('SELECT id, from_position_id, to_position_id, uci, san, role, sort_order, comment, nags FROM repertoire_move WHERE repertoire_id = ?', $id, $types);
        foreach ($rows as $row) {
            $nags = json_decode(self::string($row['nags']), true);
            $moves[] = [
                'id' => Uuid::fromBinary(self::string($row['id']))->toRfc4122(),
                'from' => $fens[Uuid::fromBinary(self::string($row['from_position_id']))->toRfc4122()],
                'to' => $fens[Uuid::fromBinary(self::string($row['to_position_id']))->toRfc4122()],
                'uci' => self::string($row['uci']),
                'san' => self::string($row['san']),
                'role' => self::string($row['role']),
                'sortOrder' => is_numeric($row['sort_order']) ? (int) $row['sort_order'] : 0,
                'comment' => \is_string($row['comment']) ? $row['comment'] : null,
                'nags' => \is_array($nags) ? array_values(array_filter($nags, 'is_int')) : [],
            ];
        }

        return new self($positions, $moves);
    }

    private static function string(mixed $value): string
    {
        return \is_string($value) ? $value : throw new \UnexpectedValueException('String expected.');
    }
}
