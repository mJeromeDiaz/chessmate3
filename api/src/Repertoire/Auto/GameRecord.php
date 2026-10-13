<?php

declare(strict_types=1);

namespace App\Repertoire\Auto;

use App\Enum\Chess\EndgameType;
use App\Enum\Repertoire\Color;

/**
 * One of the user's Lichess games, as much as the repertoire needs, from the user's point of view
 * (docs/REPERTOIRE.md, "Repertoire from my games"). Stored compact in
 * repertoire_auto_build.games ({@see self::toArray()}).
 *
 * A judgment: the ply (0-based), {@see self::INACCURACY}, {@see self::MISTAKE} or {@see self::BLUNDER},
 * Lichess' best move instead (UCI).
 *
 * The whole game is also followed to its endgame, if any (the tailored session, docs/COACH.md):
 * its kind, the plies played then, the material balance and the engine's evaluation there (analysed
 * games), both from the user's point of view.
 *
 * @phpstan-type Judgment array{int, string, string|null}
 * @phpstan-type Endgame array{string, int, int, int|null}
 * @phpstan-type GameArray array{id: string, c: string, r: int, at: int, perf: string, opp: string|null, elo: int|null, fam: string, op: string, m: list<string>, t: list<int|null>, j: list<Judgment>, a: bool, ur: int|null, e: Endgame|null}
 */
final readonly class GameRecord
{
    public const INACCURACY = 'i';
    public const MISTAKE = 'm';
    public const BLUNDER = 'b';
    /** A mate found by the engine, as centipawns. */
    public const MATE_CP = 10_000;

    /**
     * @param int                  $result     1 won, 0 drawn, -1 lost (the user's)
     * @param list<string>         $moves      UCI, from the initial position, at most the plies kept
     * @param list<int|null>       $thinks     centiseconds the user spent on each of their moves (index = ply), null on the opponent's plies or without clocks
     * @param list<Judgment>       $judgments  Lichess' judgments of the user's moves (analysed games only)
     */
    public function __construct(
        public string $id,
        public Color $color,
        public int $result,
        public int $playedAt,
        public string $perf,
        public ?string $opponent,
        public ?int $opponentRating,
        /** The opening family: Lichess' opening name before its ":" ("Sicilian Defense"). */
        public string $family,
        /** Lichess' full opening name ("Sicilian Defense: Najdorf Variation"). */
        public string $opening,
        public array $moves,
        public array $thinks,
        public array $judgments,
        /** Whether Lichess has a computer analysis of the game. */
        public bool $analysed,
        /** The user's rating in this game. */
        public ?int $rating = null,
        /** The kind of endgame the game entered, null if it never did. */
        public ?EndgameType $endgameType = null,
        /** Plies played when the endgame began. */
        public ?int $endgamePly = null,
        /** The user's points minus the opponent's then (queen 9, rook 5, bishop and knight 3, pawn 1). */
        public ?int $endgameBalance = null,
        /** The engine's evaluation then, the user's point of view, in centipawns (a mate: ±MATE_CP); analysed games only. */
        public ?int $endgameEval = null,
    ) {
    }

    /** "white:Sicilian Defense": a family is played with one color. */
    public function familyKey(): string
    {
        return $this->color->value.':'.$this->family;
    }

    /** Whether the user made a mistake or a blunder on this ply (analysed games). */
    public function errorAt(int $ply): bool
    {
        foreach ($this->judgments as [$at, $kind]) {
            if ($at === $ply && self::INACCURACY !== $kind) {
                return true;
            }
        }

        return false;
    }

    /** Lichess' best move instead of the user's mistake or blunder on this ply, if any. */
    public function bestAt(int $ply): ?string
    {
        foreach ($this->judgments as [$at, $kind, $best]) {
            if ($at === $ply && self::INACCURACY !== $kind) {
                return $best;
            }
        }

        return null;
    }

    /**
     * @return GameArray
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'c' => $this->color->value,
            'r' => $this->result,
            'at' => $this->playedAt,
            'perf' => $this->perf,
            'opp' => $this->opponent,
            'elo' => $this->opponentRating,
            'fam' => $this->family,
            'op' => $this->opening,
            'm' => $this->moves,
            't' => $this->thinks,
            'j' => $this->judgments,
            'a' => $this->analysed,
            'ur' => $this->rating,
            'e' => null === $this->endgameType || null === $this->endgamePly || null === $this->endgameBalance
                ? null
                : [$this->endgameType->value, $this->endgamePly, $this->endgameBalance, $this->endgameEval],
        ];
    }

    /**
     * @param list<self> $games
     */
    public static function listToJson(array $games): string
    {
        return json_encode(array_map(static fn (self $game): array => $game->toArray(), $games), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
    }

    /**
     * Reads back {@see self::listToJson()}, checking its shape (stored data is not trusted blindly).
     *
     * @return list<self>
     *
     * @throws \UnexpectedValueException
     */
    public static function listFromJson(string $json): array
    {
        $data = json_decode($json, true, 8, \JSON_THROW_ON_ERROR);
        if (!\is_array($data) || !array_is_list($data)) {
            throw new \UnexpectedValueException('Auto build games: list expected.');
        }

        return array_map(self::fromArray(...), $data);
    }

    /**
     * @throws \UnexpectedValueException
     */
    private static function fromArray(mixed $game): self
    {
        if (!\is_array($game)) {
            throw new \UnexpectedValueException('Auto build game: object expected.');
        }
        $judgments = [];
        foreach (self::arr($game['j'] ?? null) as $judgment) {
            $judgment = self::arr($judgment);
            $best = $judgment[2] ?? null;
            $judgments[] = [self::int($judgment[0] ?? null), self::str($judgment[1] ?? null), null === $best ? null : self::str($best)];
        }
        // Builds stored before the endgame was kept have neither "ur" nor "e".
        $endgame = null === ($game['e'] ?? null) ? null : self::arr($game['e']);
        $eval = $endgame[3] ?? null;

        return new self(
            self::str($game['id'] ?? null),
            Color::tryFrom(self::str($game['c'] ?? null)) ?? throw new \UnexpectedValueException('Auto build game: color expected.'),
            self::int($game['r'] ?? null),
            self::int($game['at'] ?? null),
            self::str($game['perf'] ?? null),
            null === ($game['opp'] ?? null) ? null : self::str($game['opp']),
            null === ($game['elo'] ?? null) ? null : self::int($game['elo']),
            self::str($game['fam'] ?? null),
            self::str($game['op'] ?? null),
            array_values(array_map(self::str(...), self::arr($game['m'] ?? null))),
            array_values(array_map(static fn (mixed $think): ?int => null === $think ? null : self::int($think), self::arr($game['t'] ?? null))),
            $judgments,
            true === ($game['a'] ?? null),
            null === ($game['ur'] ?? null) ? null : self::int($game['ur']),
            null === $endgame ? null : (EndgameType::tryFrom(self::str($endgame[0] ?? null)) ?? throw new \UnexpectedValueException('Auto build game: endgame type expected.')),
            null === $endgame ? null : self::int($endgame[1] ?? null),
            null === $endgame ? null : self::int($endgame[2] ?? null),
            null === $eval ? null : self::int($eval),
        );
    }

    /**
     * @return array<mixed>
     */
    private static function arr(mixed $value): array
    {
        return \is_array($value) ? $value : throw new \UnexpectedValueException('Auto build game: array expected.');
    }

    private static function str(mixed $value): string
    {
        return \is_string($value) ? $value : throw new \UnexpectedValueException('Auto build game: string expected.');
    }

    private static function int(mixed $value): int
    {
        return \is_int($value) ? $value : throw new \UnexpectedValueException('Auto build game: integer expected.');
    }
}
