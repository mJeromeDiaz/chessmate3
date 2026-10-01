<?php

declare(strict_types=1);

namespace App\Repertoire\Import;

/**
 * What an import contains, independent of the repertoire it will go into (color, target): the
 * positions (normalized FEN) and the moves between them, merged across games, in file order (the
 * first move from a position is its main line). Stored as JSON with the import
 * (repertoire_import.tree) between the analysis and the application.
 *
 * An OpenBook backup holds a white and a black repertoire: it is stored as one tree per side
 * ({@see self::sidesToJson()}), and the side used is the destination's color
 * ({@see self::fromStored()}).
 *
 * @phpstan-type ImportedEdge array{from: string, to: string, uci: string, san: string, comment: string|null, nags: list<int>, game: int}
 * @phpstan-type ImportWarning array{type: string, game: int, move?: string, count?: int}
 * @phpstan-type TreeArray array{positions: array<string, string>, edges: list<ImportedEdge>, starts: list<array{game: int, fen: string}>, warnings: list<ImportWarning>, games: int, color: string|null, name: string|null}
 */
final class ImportedTree
{
    /** Warning types (the front words them). */
    public const ILLEGAL_MOVE = 'illegal_move';
    public const REPEATED_POSITION = 'repeated_position';
    public const TOO_DEEP = 'too_deep';
    public const INVALID_START = 'invalid_start';
    public const START_NOT_FOUND = 'start_not_found';
    public const COMMENT_TRUNCATED = 'comment_truncated';
    /** OpenBook: a position of the file that is not a valid one, or not the side's to move. */
    public const INVALID_POSITION = 'invalid_position';
    /** OpenBook: positions of the file the initial position does not lead to (count). */
    public const UNREACHABLE = 'unreachable';

    /** @var array<string, string> normalized FEN => side to move ('w' or 'b') */
    public array $positions = [];
    /** @var list<ImportedEdge> */
    public array $edges = [];
    /** @var list<array{game: int, fen: string}> games that start from another position than the initial one */
    public array $starts = [];
    /** @var list<ImportWarning> */
    public array $warnings = [];
    public int $games = 0;
    /** 'white' or 'black' when the file tells (Orientation tag of its first game). */
    public ?string $color = null;
    /** A name for a new repertoire (Event tag of the first game), when there is a meaningful one. */
    public ?string $name = null;

    /** @var array<string, int> "from uci" => index in $edges */
    private array $edgeIndex = [];

    /**
     * @param list<int> $nags
     *
     * @return bool whether the move is new (the first occurrence keeps its place and annotations,
     *              later ones only fill what it lacks)
     */
    public function addEdge(string $from, string $to, string $turnAfter, string $uci, string $san, ?string $comment, array $nags, int $game): bool
    {
        $this->positions[$to] ??= $turnAfter;
        $key = $from.' '.$uci;
        if (isset($this->edgeIndex[$key])) {
            $edge = &$this->edges[$this->edgeIndex[$key]];
            $edge['comment'] ??= $comment;
            if ([] === $edge['nags']) {
                $edge['nags'] = $nags;
            }

            return false;
        }
        $this->edgeIndex[$key] = \count($this->edges);
        $this->edges[] = ['from' => $from, 'to' => $to, 'uci' => $uci, 'san' => $san, 'comment' => $comment, 'nags' => $nags, 'game' => $game];

        return true;
    }

    /**
     * @param string|null $move  the move concerned, as in the file ("7...h5")
     * @param int|null    $count how many things the warning is about
     */
    public function warn(string $type, int $game, ?string $move = null, ?int $count = null): void
    {
        $warning = ['type' => $type, 'game' => $game];
        if (null !== $move) {
            $warning['move'] = $move;
        }
        if (null !== $count) {
            $warning['count'] = $count;
        }
        $this->warnings[] = $warning;
    }

    /**
     * @return TreeArray
     */
    public function toArray(): array
    {
        return [
            'positions' => $this->positions,
            'edges' => $this->edges,
            'starts' => $this->starts,
            'warnings' => $this->warnings,
            'games' => $this->games,
            'color' => $this->color,
            'name' => $this->name,
        ];
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
    }

    /**
     * One tree per side (an OpenBook backup), with the side to suggest.
     */
    public static function sidesToJson(self $white, self $black, string $suggested): string
    {
        return json_encode(
            ['sides' => ['white' => $white->toArray(), 'black' => $black->toArray()], 'suggested' => $suggested],
            \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES,
        );
    }

    /**
     * Reads back a stored tree: {@see self::toJson()}, or the side $color (default: the suggested
     * one) of {@see self::sidesToJson()}.
     *
     * @param 'white'|'black'|null $color
     *
     * @throws \UnexpectedValueException
     */
    public static function fromStored(string $json, ?string $color = null): self
    {
        $data = json_decode($json, true, 16, \JSON_THROW_ON_ERROR);
        if (!\is_array($data)) {
            throw new \UnexpectedValueException('Import tree: object expected.');
        }
        if (!isset($data['sides'])) {
            return self::fromData($data);
        }
        $sides = self::arr($data['sides']);
        $side = $color ?? self::str($data['suggested'] ?? null);

        return self::fromData(self::arr($sides[$side] ?? null));
    }

    /**
     * Reads back {@see self::toJson()}, checking its shape (stored data is not trusted blindly).
     *
     * @throws \UnexpectedValueException
     */
    public static function fromJson(string $json): self
    {
        $data = json_decode($json, true, 16, \JSON_THROW_ON_ERROR);
        if (!\is_array($data)) {
            throw new \UnexpectedValueException('Import tree: object expected.');
        }

        return self::fromData($data);
    }

    /**
     * @param array<mixed> $data
     *
     * @throws \UnexpectedValueException
     */
    private static function fromData(array $data): self
    {
        $positions = [];
        foreach (self::arr($data['positions'] ?? null) as $fen => $turn) {
            $positions[(string) $fen] = self::str($turn);
        }
        $edges = [];
        foreach (self::arr($data['edges'] ?? null) as $edge) {
            $edge = self::arr($edge);
            $edges[] = [
                'from' => self::str($edge['from'] ?? null),
                'to' => self::str($edge['to'] ?? null),
                'uci' => self::str($edge['uci'] ?? null),
                'san' => self::str($edge['san'] ?? null),
                'comment' => null === ($edge['comment'] ?? null) ? null : self::str($edge['comment']),
                'nags' => array_values(array_filter(self::arr($edge['nags'] ?? []), 'is_int')),
                'game' => self::int($edge['game'] ?? null),
            ];
        }
        $starts = [];
        foreach (self::arr($data['starts'] ?? []) as $start) {
            $start = self::arr($start);
            $starts[] = ['game' => self::int($start['game'] ?? null), 'fen' => self::str($start['fen'] ?? null)];
        }
        $warnings = [];
        foreach (self::arr($data['warnings'] ?? []) as $warning) {
            $warning = self::arr($warning);
            $item = ['type' => self::str($warning['type'] ?? null), 'game' => self::int($warning['game'] ?? null)];
            if (isset($warning['move'])) {
                $item['move'] = self::str($warning['move']);
            }
            if (isset($warning['count'])) {
                $item['count'] = self::int($warning['count']);
            }
            $warnings[] = $item;
        }

        return self::fromArray([
            'positions' => $positions,
            'edges' => $edges,
            'starts' => $starts,
            'warnings' => $warnings,
            'games' => self::int($data['games'] ?? null),
            'color' => null === ($data['color'] ?? null) ? null : self::str($data['color']),
            'name' => null === ($data['name'] ?? null) ? null : self::str($data['name']),
        ]);
    }

    /**
     * @param TreeArray $data
     */
    public static function fromArray(array $data): self
    {
        $tree = new self();
        $tree->positions = $data['positions'];
        $tree->starts = $data['starts'];
        $tree->warnings = $data['warnings'];
        $tree->games = $data['games'];
        $tree->color = $data['color'];
        $tree->name = $data['name'];
        foreach ($data['edges'] as $edge) {
            $tree->edgeIndex[$edge['from'].' '.$edge['uci']] = \count($tree->edges);
            $tree->edges[] = $edge;
        }

        return $tree;
    }

    /**
     * @return array<mixed>
     */
    private static function arr(mixed $value): array
    {
        return \is_array($value) ? $value : throw new \UnexpectedValueException('Import tree: array expected.');
    }

    private static function str(mixed $value): string
    {
        return \is_string($value) ? $value : throw new \UnexpectedValueException('Import tree: string expected.');
    }

    private static function int(mixed $value): int
    {
        return \is_int($value) ? $value : throw new \UnexpectedValueException('Import tree: integer expected.');
    }
}
