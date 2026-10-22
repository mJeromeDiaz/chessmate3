<?php

declare(strict_types=1);

namespace App\Endgame\Tablebase;

use App\Enum\Endgame\Category;
use App\Enum\Endgame\Result;

/**
 * What the Lichess tablebase says of a position (docs/ENDGAMES.md, § 2): its category for the side
 * to move, its distances, and every legal move, best first. Distances are counted in plies; DTM
 * (distance to mate) is known up to 5 pieces, DTZ (distance to a capture or pawn move, under the
 * 50-move rule) up to 7.
 *
 * Kept compact in `endgame_tablebase.answer` ({@see self::toStored()}): about 1 KB a position.
 */
final readonly class Answer
{
    /**
     * @param list<MoveAnswer> $moves best first, as Lichess orders them
     */
    public function __construct(
        public Category $category,
        public ?int $dtz,
        public ?int $dtm,
        public bool $checkmate,
        public bool $stalemate,
        public bool $insufficientMaterial,
        public array $moves,
    ) {
    }

    /** The result for the side to move, null when the tablebase cannot tell it. */
    public function result(): ?Result
    {
        return $this->category->result();
    }

    public function move(string $uci): ?MoveAnswer
    {
        foreach ($this->moves as $move) {
            if ($move->uci === $uci) {
                return $move;
            }
        }

        return null;
    }

    /**
     * The moves that keep the result of the position for the side to move (all of them in a lost
     * position).
     *
     * @return list<MoveAnswer>
     */
    public function keepingMoves(): array
    {
        $result = $this->result();

        return array_values(array_filter($this->moves, static fn (MoveAnswer $move): bool => null !== $result && $move->resultForMover() === $result));
    }

    /**
     * @param array<mixed> $body the JSON of GET /standard
     */
    public static function fromLichess(array $body): self
    {
        $moves = [];
        foreach (\is_array($body['moves'] ?? null) ? $body['moves'] : [] as $move) {
            if (!\is_array($move) || !\is_string($move['uci'] ?? null)) {
                continue;
            }
            $moves[] = new MoveAnswer(
                $move['uci'],
                self::category($move['category'] ?? null),
                self::int($move['dtz'] ?? null),
                self::int($move['dtm'] ?? null),
                true === ($move['zeroing'] ?? false),
                true === ($move['checkmate'] ?? false),
                true === ($move['stalemate'] ?? false),
            );
        }

        return new self(
            self::category($body['category'] ?? null),
            self::int($body['dtz'] ?? null),
            self::int($body['dtm'] ?? null),
            true === ($body['checkmate'] ?? false),
            true === ($body['stalemate'] ?? false),
            true === ($body['insufficient_material'] ?? false),
            $moves,
        );
    }

    /**
     * The compact form kept in the database: `c` category, `z` DTZ, `m` DTM, `f` flags (1 checkmate,
     * 2 stalemate, 4 insufficient material), `mv` the moves as [uci, category, dtz, dtm, flags]
     * (1 zeroing, 2 checkmate, 4 stalemate).
     *
     * @return array{c: string, z: ?int, m: ?int, f: int, mv: list<array{string, string, ?int, ?int, int}>}
     */
    public function toStored(): array
    {
        return [
            'c' => $this->category->value,
            'z' => $this->dtz,
            'm' => $this->dtm,
            'f' => ($this->checkmate ? 1 : 0) | ($this->stalemate ? 2 : 0) | ($this->insufficientMaterial ? 4 : 0),
            'mv' => array_map(static fn (MoveAnswer $move): array => [
                $move->uci,
                $move->category->value,
                $move->dtz,
                $move->dtm,
                ($move->zeroing ? 1 : 0) | ($move->checkmate ? 2 : 0) | ($move->stalemate ? 4 : 0),
            ], $this->moves),
        ];
    }

    /**
     * @param array<mixed> $stored {@see self::toStored()}
     */
    public static function fromStored(array $stored): self
    {
        $moves = [];
        foreach (\is_array($stored['mv'] ?? null) ? $stored['mv'] : [] as $move) {
            if (!\is_array($move) || !\is_string($move[0] ?? null)) {
                continue;
            }
            $flags = self::int($move[4] ?? null) ?? 0;
            $moves[] = new MoveAnswer($move[0], self::category($move[1] ?? null), self::int($move[2] ?? null), self::int($move[3] ?? null), 0 !== ($flags & 1), 0 !== ($flags & 2), 0 !== ($flags & 4));
        }
        $flags = self::int($stored['f'] ?? null) ?? 0;

        return new self(self::category($stored['c'] ?? null), self::int($stored['z'] ?? null), self::int($stored['m'] ?? null), 0 !== ($flags & 1), 0 !== ($flags & 2), 0 !== ($flags & 4), $moves);
    }

    private static function category(mixed $value): Category
    {
        return (\is_string($value) ? Category::tryFrom($value) : null) ?? Category::Unknown;
    }

    private static function int(mixed $value): ?int
    {
        return \is_int($value) ? $value : null;
    }
}
