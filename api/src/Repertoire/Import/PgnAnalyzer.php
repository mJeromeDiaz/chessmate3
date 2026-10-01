<?php

declare(strict_types=1);

namespace App\Repertoire\Import;

use App\Chess\InvalidPositionException;
use App\Chess\Pgn\Game;
use App\Chess\Pgn\Nag;
use App\Chess\Pgn\Node;
use App\Chess\Pgn\Parser;
use App\Chess\Pgn\PgnSyntaxException;
use App\Chess\Rules;
use App\Entity\Repertoire\Move;
use App\Repertoire\Limits;

/**
 * Reads a PGN file (games, study chapters) into an {@see ImportedTree}: every move is replayed and
 * checked (App\Chess\Rules), positions are normalized so that games and transpositions merge.
 * docs/REPERTOIRE.md, "Import".
 *
 * - An illegal (or ambiguous) move ends its line: what precedes is kept, a warning names the game
 *   and the move.
 * - A move back to a position its own line comes from ends the line too (the repertoire is
 *   acyclic); so does a move beyond the depth limit.
 * - A game starting from a FEN is kept as is: whether it can be attached (its position must be in
 *   the file or in the target repertoire) is decided when the import is planned.
 * - Comments are plain text (the one before a move and the one after it, joined), cut at the
 *   comment limit; NAGs beyond what a move accepts are dropped.
 */
final readonly class PgnAnalyzer
{
    public function __construct(
        private Limits $limits,
    ) {
    }

    /**
     * @param (callable(int, int): void)|null $progress told the games done and the total, after each game
     *
     * @throws ImportRejectedException
     */
    public function analyze(string $pgn, ?callable $progress = null): ImportedTree
    {
        if (\strlen($pgn) > $this->limits->maxImportBytes) {
            throw new ImportRejectedException(ImportRejectedException::TOO_LARGE, sprintf('%d bytes at most.', $this->limits->maxImportBytes));
        }
        try {
            $games = (new Parser())->parse($pgn);
        } catch (PgnSyntaxException $e) {
            throw new ImportRejectedException(ImportRejectedException::SYNTAX, $e->getMessage(), $e->pgnLine);
        }
        if (\count($games) > $this->limits->maxImportGames) {
            throw new ImportRejectedException(ImportRejectedException::TOO_MANY_GAMES, sprintf('%d games at most.', $this->limits->maxImportGames));
        }

        $tree = new ImportedTree();
        $initial = Rules::initial()->normalizedFen();
        $tree->positions[$initial] = 'w';
        foreach ($games as $i => $game) {
            $number = $i + 1;
            if (0 === $i) {
                $tree->color = self::color($game);
                $tree->name = self::name($game);
            }
            $this->game($tree, $game, $number, $initial);
            if (\count($tree->positions) > $this->limits->maxPositions) {
                throw new ImportRejectedException(ImportRejectedException::TOO_MANY_POSITIONS, sprintf('%d positions at most.', $this->limits->maxPositions));
            }
            if (null !== $progress) {
                $progress($number, \count($games));
            }
        }
        $tree->games = \count($games);
        if ([] === $tree->edges) {
            throw new ImportRejectedException(ImportRejectedException::EMPTY, 'No legal move to import.');
        }

        return $tree;
    }

    private function game(ImportedTree $tree, Game $game, int $number, string $initial): void
    {
        $start = $initial;
        $ply = 0;
        if (null !== $fen = $game->startingFen()) {
            try {
                $rules = Rules::fromFen($fen);
            } catch (InvalidPositionException) {
                $tree->warn(ImportedTree::INVALID_START, $number);

                return;
            }
            $start = $rules->normalizedFen();
            $ply = self::startingPly($fen);
            if ($start !== $initial) {
                $tree->positions[$start] ??= $rules->sideToMove();
                $tree->starts[] = ['game' => $number, 'fen' => $start];
            }
        }

        // Iterative walk; each entry carries its position (and the rules in that position, reused
        // by the main line: rebuilding them from the FEN is the costly part) and the positions of
        // its own line (cycle check).
        /** @var list<array{Node, string, int, array<string, true>, Rules|null}> $stack */
        $stack = [[$game->root, $start, $ply, [$start => true], null]];
        $tooDeep = false;
        $edges = $notes = [];
        while ([] !== $stack) {
            [$node, $fen, $ply, $line, $state] = array_pop($stack);
            $next = [];
            // Variations first: the main line then plays on the parent's rules.
            $children = $node->children;
            $order = [] === $children ? [] : [...range(1, \count($children) - 1), 0];
            if (1 === \count($children)) {
                $order = [0];
            }
            foreach ($order as $index) {
                $child = $children[$index];
                $label = self::label($ply, $child->san);
                if ($ply + 1 > $this->limits->maxDepth) {
                    $notes[$index][] = [ImportedTree::TOO_DEEP, $label];
                    continue;
                }
                $rules = 0 === $index && null !== $state ? $state : Rules::fromFen($fen);
                $move = $rules->playSan($child->san);
                if (null === $move) {
                    $notes[$index][] = [ImportedTree::ILLEGAL_MOVE, $label];
                    continue;
                }
                $to = $rules->normalizedFen();
                if (isset($line[$to])) {
                    $notes[$index][] = [ImportedTree::REPEATED_POSITION, $label];
                    continue;
                }
                [$comment, $truncated] = self::comment($child);
                if ($truncated) {
                    $notes[$index][] = [ImportedTree::COMMENT_TRUNCATED, $label];
                }
                $edges[$index] = [$fen, $to, $rules->sideToMove(), Rules::uci($move), (string) $move->san, $comment, self::nags($child->nags)];
                $next[$index] = [$child, $to, $ply + 1, $line + [$to => true], $rules];
            }
            // Edges in file order (main line first), and the main line popped first.
            for ($index = 0, $count = \count($children); $index < $count; ++$index) {
                foreach ($notes[$index] ?? [] as [$type, $label]) {
                    // One depth warning per game: the first line cut, in file order.
                    if (ImportedTree::TOO_DEEP === $type) {
                        if ($tooDeep) {
                            continue;
                        }
                        $tooDeep = true;
                    }
                    $tree->warn($type, $number, $label);
                }
                if (isset($edges[$index])) {
                    [$from, $to, $turn, $uci, $san, $comment, $nags] = $edges[$index];
                    $tree->addEdge($from, $to, $turn, $uci, $san, $comment, $nags, $number);
                }
            }
            $edges = $notes = [];
            krsort($next);
            array_push($stack, ...array_values($next));
        }
    }

    /**
     * The comment before the move and the one after it, as plain text within the limit.
     *
     * @return array{string|null, bool} the comment, whether it was cut
     */
    private static function comment(Node $node): array
    {
        $parts = array_filter([$node->startingComment, $node->comment], static fn (?string $part): bool => null !== $part && '' !== trim($part));

        return [] === $parts ? [null, false] : self::plainText(implode("\n", $parts));
    }

    /**
     * A comment as stored: plain text without control characters, within the comment limit.
     *
     * @return array{string|null, bool} the text (null when empty), whether it was cut
     */
    public static function plainText(string $text): array
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = trim((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text));
        if ('' === $text) {
            return [null, false];
        }
        if (mb_strlen($text) <= Move::COMMENT_MAX_LENGTH) {
            return [$text, false];
        }

        return [rtrim(mb_substr($text, 0, Move::COMMENT_MAX_LENGTH - 1)).'…', true];
    }

    /**
     * What a move accepts (App\Repertoire\Graph\Annotation): distinct, known, one move assessment,
     * a few at most.
     *
     * @param list<int> $nags
     *
     * @return list<int>
     */
    private static function nags(array $nags): array
    {
        $kept = [];
        $assessed = false;
        foreach (array_unique($nags) as $nag) {
            if ($nag < 1 || $nag > Nag::MAX || \count($kept) >= Move::MAX_NAGS) {
                continue;
            }
            if ($nag <= Nag::DUBIOUS) {
                if ($assessed) {
                    continue;
                }
                $assessed = true;
            }
            $kept[] = $nag;
        }
        sort($kept);

        return $kept;
    }

    /** "3.Bf4" or "7...h5", as in the file. */
    private static function label(int $ply, string $san): string
    {
        return (intdiv($ply, 2) + 1).(0 === $ply % 2 ? '.' : '...').$san;
    }

    /** Plies before the FEN's position (from its move number and side to move). */
    private static function startingPly(string $fen): int
    {
        $fields = preg_split('/\s+/', trim($fen)) ?: [];
        $number = isset($fields[5]) && ctype_digit($fields[5]) ? max(1, (int) $fields[5]) : 1;

        return ($number - 1) * 2 + ('b' === ($fields[1] ?? 'w') ? 1 : 0);
    }

    private static function color(Game $game): ?string
    {
        $orientation = strtolower(trim((string) $game->tag('Orientation')));

        return \in_array($orientation, ['white', 'black'], true) ? $orientation : null;
    }

    /** The Event tag, without a study's chapter part ("Italian: chapter 1" → "Italian"). */
    private static function name(Game $game): ?string
    {
        $event = trim((string) $game->tag('Event'));
        if ('' === $event || '?' === $event) {
            return null;
        }
        if (str_contains((string) $game->tag('Site'), '/study/') && str_contains($event, ': ')) {
            $event = explode(': ', $event, 2)[0];
        }

        return mb_substr($event, 0, 80);
    }
}
