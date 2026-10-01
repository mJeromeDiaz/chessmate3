<?php

declare(strict_types=1);

namespace App\Chess\Pgn;

/**
 * Splits a PGN text (PGN standard, section 8) into tokens: tag pairs, comments ("{...}" and ";" to
 * the end of the line), SAN moves, NAGs ("$n" and the "!", "?"... suffixes), variation parentheses
 * and game results. Move numbers are dropped, "%" escape lines skipped. Moves are not checked
 * here, only their shape.
 */
final class Tokenizer
{
    private const TAG = '/\G\[\s*([A-Za-z0-9_]+)\s*"((?:[^"\\\\]|\\\\.)*)"\s*\]/';
    private const RESULT = '/\G(1-0|0-1|1\/2-1\/2|\*)(?![A-Za-z0-9_\-\/])/';
    /** "12." "12..." "12…", possibly glued to the move ("1.e4"). */
    private const MOVE_NUMBER = '/\G\d+\s*(?:\.+|…)/u';
    private const DOTS = '/\G(?:\.+|…)/u';
    /** A move ("Nf3", "O-O", "e8=Q+"), possibly with the "e.p." suffix ("exd6e.p."). */
    private const SYMBOL = '/\G(?:[A-Za-z0-9][A-Za-z0-9_+#=:\-]*?e\.p\.|[A-Za-z0-9][A-Za-z0-9_+#=:\-]*)/';
    private const SUFFIX = '/\G(?:!!|\?\?|!\?|\?!|!|\?)/';
    private const NAG = '/\G\$(\d{1,3})/';

    /**
     * @return list<Token>
     *
     * @throws PgnSyntaxException
     */
    public function tokenize(string $pgn): array
    {
        $tokens = [];
        $length = \strlen($pgn);
        $offset = 0;
        $line = 1;

        while ($offset < $length) {
            $char = $pgn[$offset];

            if ("\n" === $char) {
                ++$line;
                ++$offset;
                if ($offset < $length && '%' === $pgn[$offset]) {
                    $offset = $this->endOfLine($pgn, $offset);
                }
                continue;
            }
            if (ctype_space($char)) {
                ++$offset;
                continue;
            }
            if (0 === $offset && '%' === $char) {
                $offset = $this->endOfLine($pgn, $offset);
                continue;
            }

            if ('{' === $char) {
                $end = strpos($pgn, '}', $offset);
                if (false === $end) {
                    throw new PgnSyntaxException('Unclosed comment', $line);
                }
                $text = substr($pgn, $offset + 1, $end - $offset - 1);
                $tokens[] = new Token(Token::COMMENT, $text, $line);
                $line += substr_count($text, "\n");
                $offset = $end + 1;
                continue;
            }
            if (';' === $char) {
                $end = $this->endOfLine($pgn, $offset);
                $tokens[] = new Token(Token::COMMENT, substr($pgn, $offset + 1, $end - $offset - 1), $line);
                $offset = $end;
                continue;
            }
            if ('[' === $char) {
                if (1 !== preg_match(self::TAG, $pgn, $m, 0, $offset)) {
                    throw new PgnSyntaxException('Malformed tag pair', $line);
                }
                $tokens[] = new Token(Token::TAG, stripslashes($m[2]), $line, $m[1]);
                $line += substr_count($m[0], "\n");
                $offset += \strlen($m[0]);
                continue;
            }
            if ('(' === $char || ')' === $char) {
                $tokens[] = new Token('(' === $char ? Token::OPEN : Token::CLOSE, $char, $line);
                ++$offset;
                continue;
            }
            if (1 === preg_match(self::NAG, $pgn, $m, 0, $offset)) {
                if ((int) $m[1] > Nag::MAX) {
                    throw new PgnSyntaxException('NAG out of range', $line);
                }
                $tokens[] = new Token(Token::NAG, $m[1], $line);
                $offset += \strlen($m[0]);
                continue;
            }
            if (1 === preg_match(self::SUFFIX, $pgn, $m, 0, $offset)) {
                $tokens[] = new Token(Token::NAG, (string) Nag::fromSuffix($m[0]), $line);
                $offset += \strlen($m[0]);
                continue;
            }
            if (1 === preg_match(self::RESULT, $pgn, $m, 0, $offset)) {
                $tokens[] = new Token(Token::RESULT, $m[1], $line);
                $offset += \strlen($m[0]);
                continue;
            }
            if (1 === preg_match(self::MOVE_NUMBER, $pgn, $m, 0, $offset) || 1 === preg_match(self::DOTS, $pgn, $m, 0, $offset)) {
                $offset += \strlen($m[0]);
                continue;
            }
            if (1 === preg_match(self::SYMBOL, $pgn, $m, 0, $offset)) {
                // A bare number ("1 e4") is a move number without its period.
                if (!ctype_digit($m[0])) {
                    $tokens[] = new Token(Token::SAN, $m[0], $line);
                }
                $offset += \strlen($m[0]);
                continue;
            }

            throw new PgnSyntaxException(sprintf('Unexpected character "%s"', mb_substr(substr($pgn, $offset, 4), 0, 1)), $line);
        }

        return $tokens;
    }

    /** Offset of the newline ending the line at $offset (or of the end of the text). */
    private function endOfLine(string $pgn, int $offset): int
    {
        $end = strpos($pgn, "\n", $offset);

        return false === $end ? \strlen($pgn) : $end;
    }
}
