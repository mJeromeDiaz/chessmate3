<?php

declare(strict_types=1);

namespace App\Puzzle\Import;

/**
 * Validates the fields of one export line against the `puzzle` columns (docs/PUZZLE_IMPORT.md,
 * § 2): a value that would not fit, or would be stored wrong, rejects the line instead of being
 * truncated or cast.
 */
final class LineParser
{
    private const LICHESS_ID = '/^[A-Za-z0-9]{5}$/';
    private const FEN = '~^[1-8pnbrqkPNBRQK/]+ [wb] (-|[KQkq]{1,4}) (-|[a-h][36]) \d+ \d+$~';
    /** At least two moves: the opponent's, then the player's answer. */
    private const MOVES = '/^[a-h][1-8][a-h][1-8][qrbn]?( [a-h][1-8][a-h][1-8][qrbn]?)+$/';
    /** Theme keys and opening tags: letters, digits, `_` and `-` ("Benoni-Indian_Defense"). */
    private const KEYS = '/^[\w-]+( [\w-]+)*$/';
    private const DATE = '/^\d{4}-\d{2}-\d{2}$/';

    /**
     * @param list<string|null> $fields PuzzleId, FEN, Moves, Rating, RatingDeviation, Popularity,
     *                                  NbPlays, Themes, GameUrl, OpeningTags (optional), DailyDate (optional)
     *
     * @throws InvalidLineException
     */
    public static function parse(array $fields): CsvRow
    {
        if (\count($fields) < 9) {
            throw new InvalidLineException(\sprintf('%d columns, 9 to 11 expected', \count($fields)));
        }
        $field = static fn (int $i): string => trim($fields[$i] ?? '');

        $lichessId = $field(0);
        if (1 !== preg_match(self::LICHESS_ID, $lichessId)) {
            throw new InvalidLineException(\sprintf('puzzle id "%s"', $lichessId));
        }
        $fen = $field(1);
        if (\strlen($fen) > 92 || 1 !== preg_match(self::FEN, $fen)) {
            throw new InvalidLineException('FEN');
        }
        $moves = $field(2);
        if (\strlen($moves) > 255 || 1 !== preg_match(self::MOVES, $moves)) {
            throw new InvalidLineException('moves');
        }
        $gameUrl = $field(8);
        if (\strlen($gameUrl) > 255 || !str_starts_with($gameUrl, 'https://lichess.org/')) {
            throw new InvalidLineException('game URL');
        }
        $dailyDate = $field(10);
        if ('' !== $dailyDate && 1 !== preg_match(self::DATE, $dailyDate)) {
            throw new InvalidLineException('daily date');
        }

        return new CsvRow(
            $lichessId,
            $fen,
            $moves,
            self::integer($field(3), 0, 65535, 'rating'),
            self::integer($field(4), 0, 65535, 'rating deviation'),
            self::integer($field(5), -100, 100, 'popularity'),
            self::integer($field(6), 0, 4294967295, 'play count'),
            self::keys($field(7), 'themes') ?? [],
            $gameUrl,
            self::keys($field(9), 'opening tags'),
            '' === $dailyDate ? null : $dailyDate,
        );
    }

    private static function integer(string $value, int $min, int $max, string $name): int
    {
        if (1 !== preg_match('/^-?\d{1,10}$/', $value) || (int) $value < $min || (int) $value > $max) {
            throw new InvalidLineException(\sprintf('%s "%s"', $name, $value));
        }

        return (int) $value;
    }

    /**
     * Space-separated keys (themes, opening tags), turned into a JSON list as they are stored.
     *
     * @return list<string>|null null when empty
     */
    private static function keys(string $value, string $name): ?array
    {
        if ('' === $value) {
            return null;
        }
        if (\strlen($value) > 600 || 1 !== preg_match(self::KEYS, $value)) {
            throw new InvalidLineException($name);
        }

        return explode(' ', $value);
    }
}
