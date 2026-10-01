<?php

declare(strict_types=1);

namespace App\Chess\Pgn;

/**
 * Writes games ({@see Game}) as PGN export text: tag pairs in the given order, then the movetext
 * with move numbers, NAGs as "$n", comments, nested variations in parentheses, wrapped at 80
 * columns, and the result. Parsing the output gives back the same tree (tested).
 *
 * Comments are plain text: a "}" would end the comment early and is replaced by ")"; the
 * [%...] commands are written back inside the comment.
 */
final class Writer
{
    public const LINE_WIDTH = 80;

    /**
     * @param list<Game> $games
     */
    public function writeAll(array $games): string
    {
        return implode("\n", array_map($this->write(...), $games));
    }

    public function write(Game $game): string
    {
        $text = '';
        foreach ($game->tags as $name => $value) {
            $text .= sprintf("[%s \"%s\"]\n", $name, addcslashes($value, '"\\'));
        }
        if ('' !== $text) {
            $text .= "\n";
        }

        $tokens = [];
        $root = $game->root;
        $this->comment($tokens, $root->startingComment, $root->commands);
        $this->sequence($tokens, $root, $this->startingPly($game), true);
        $tokens[] = $game->result ?? '*';

        return $text.wordwrap($this->join($tokens), self::LINE_WIDTH, "\n", false)."\n";
    }

    /**
     * The line continuing from $parent: its main child, that child's variations, then on.
     *
     * @param list<string> $tokens
     */
    private function sequence(array &$tokens, Node $parent, int $ply, bool $forceNumber): void
    {
        while (null !== $main = $parent->mainChild()) {
            $forceNumber = $this->move($tokens, $main, $ply, $forceNumber);
            $variations = \array_slice($parent->children, 1);
            foreach ($variations as $variation) {
                $tokens[] = '(';
                $this->sequence($tokens, $variation, $ply + 1, $this->move($tokens, $variation, $ply, true));
                $tokens[] = ')';
            }
            $forceNumber = $forceNumber || [] !== $variations;
            $parent = $main;
            ++$ply;
        }
    }

    /**
     * @param list<string> $tokens
     *
     * @return bool whether the next move needs its number (a comment was written after this one)
     */
    private function move(array &$tokens, Node $node, int $ply, bool $forceNumber): bool
    {
        if (null !== $node->startingComment) {
            $this->comment($tokens, $node->startingComment, []);
            $forceNumber = true;
        }
        $number = intdiv($ply, 2) + 1;
        if (0 === $ply % 2) {
            $tokens[] = $number.'.';
        } elseif ($forceNumber) {
            $tokens[] = $number.'...';
        }
        $tokens[] = $node->san;
        foreach ($node->nags as $nag) {
            $tokens[] = '$'.$nag;
        }
        return $this->comment($tokens, $node->comment, $node->commands);
    }

    /**
     * @param list<string>                $tokens
     * @param list<array{string, string}> $commands
     */
    private function comment(array &$tokens, ?string $text, array $commands): bool
    {
        $parts = array_map(static fn (array $command): string => sprintf('[%%%s %s]', $command[0], $command[1]), $commands);
        if (null !== $text) {
            $parts[] = $text;
        }
        if ([] === $parts) {
            return false;
        }
        $tokens[] = '{'.str_replace('}', ')', implode(' ', $parts)).'}';

        return true;
    }

    /**
     * 0 for white's first move; from the FEN tag when the game starts elsewhere.
     */
    private function startingPly(Game $game): int
    {
        $fields = explode(' ', (string) $game->startingFen());
        if (\count($fields) < 2) {
            return 0;
        }
        $moveNumber = isset($fields[5]) && ctype_digit($fields[5]) ? max(1, (int) $fields[5]) : 1;

        return ($moveNumber - 1) * 2 + ('b' === $fields[1] ? 1 : 0);
    }

    /**
     * @param list<string> $tokens
     */
    private function join(array $tokens): string
    {
        $text = '';
        $previous = null;
        foreach ($tokens as $token) {
            if (null !== $previous && '(' !== $previous && ')' !== $token) {
                $text .= ' ';
            }
            $text .= $token;
            $previous = $token;
        }

        return $text;
    }
}
