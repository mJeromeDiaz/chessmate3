<?php

declare(strict_types=1);

namespace App\Chess\Pgn;

/**
 * Parses PGN text into games ({@see Game}): tags, move tree with nested variations, comments
 * (before and after moves, with their embedded [%...] commands set apart), NAGs and results.
 * Several games follow each other, separated by their tags or results. Moves are kept as
 * written: their legality is checked by whoever replays them (App\Chess\Rules).
 *
 * A text that is not valid UTF-8 is read as Windows-1252 (the PGN standard's character set is
 * ISO 8859-1, which it contains). Variations deeper than {@see self::MAX_VARIATION_DEPTH} are
 * rejected: they are never honest and the writer recurses on them.
 *
 * Not reentrant: the state of the game being read lives in the instance during {@see self::parse()}.
 */
final class Parser
{
    public const MAX_VARIATION_DEPTH = 32;

    private const COMMAND = '/\[%([A-Za-z0-9_]+)\s*([^\]]*)\]/';

    /** @var list<Game> */
    private array $games = [];
    private ?Game $game = null;
    /** The move after which the next move is played (the game root before the first one). */
    private Node $cursor;
    /** @var list<Node> the move each open variation returns to */
    private array $stack = [];
    /** @var \WeakMap<Node, Node> */
    private \WeakMap $parents;
    /** Right after "(" or at the start of the movetext: a comment then precedes the next move. */
    private bool $atStart = true;
    private ?string $pending = null;
    /** @var list<array{string, string}> */
    private array $pendingCommands = [];
    private bool $hasMoves = false;

    public function __construct(
        private readonly Tokenizer $tokenizer = new Tokenizer(),
    ) {
        $this->cursor = new Node();
        $this->parents = new \WeakMap();
    }

    /**
     * @return list<Game>
     *
     * @throws PgnSyntaxException
     */
    public function parse(string $pgn): array
    {
        if (str_starts_with($pgn, "\u{FEFF}")) {
            $pgn = substr($pgn, 3);
        }
        if (!mb_check_encoding($pgn, 'UTF-8')) {
            $pgn = mb_convert_encoding($pgn, 'UTF-8', 'Windows-1252');
        }

        $this->games = [];
        $this->game = null;
        $this->stack = [];
        $this->parents = new \WeakMap();
        try {
            foreach ($this->tokenizer->tokenize($pgn) as $token) {
                $this->consume($token);
            }
            $this->finish(substr_count($pgn, "\n") + 1);

            return $this->games;
        } finally {
            $this->game = null;
            $this->games = [];
            $this->stack = [];
        }
    }

    /**
     * @throws PgnSyntaxException
     */
    private function consume(Token $token): void
    {
        switch ($token->type) {
            case Token::TAG:
                if (null !== $this->game && ($this->hasMoves || null !== $this->game->result)) {
                    $this->finish($token->line);
                }
                $this->open($token->line)->tags[$token->name] = $token->value;
                break;

            case Token::COMMENT:
                $this->open($token->line);
                [$text, $commands] = self::comment($token->value);
                if ($this->atStart) {
                    $this->pending = self::join($this->pending, $text);
                    array_push($this->pendingCommands, ...$commands);
                } else {
                    $this->cursor->comment = self::join($this->cursor->comment, $text);
                    array_push($this->cursor->commands, ...$commands);
                }
                break;

            case Token::SAN:
                if (null !== $this->game?->result) {
                    $this->finish($token->line);
                }
                $this->open($token->line);
                $node = new Node($token->value, $token->line);
                $node->startingComment = $this->pending;
                $node->commands = $this->pendingCommands;
                $this->pending = null;
                $this->pendingCommands = [];
                $this->cursor->children[] = $node;
                $this->parents[$node] = $this->cursor;
                $this->cursor = $node;
                $this->atStart = false;
                $this->hasMoves = true;
                break;

            case Token::NAG:
                if (!$this->atStart && !$this->cursor->isRoot()) {
                    $this->cursor->nags[] = (int) $token->value;
                }
                break;

            case Token::OPEN:
                $parent = $this->parents[$this->cursor] ?? null;
                if (null === $this->game || null === $parent) {
                    throw new PgnSyntaxException('Variation before any move', $token->line);
                }
                if (\count($this->stack) >= self::MAX_VARIATION_DEPTH) {
                    throw new PgnSyntaxException('Variations nested too deeply', $token->line);
                }
                $this->stack[] = $this->cursor;
                $this->cursor = $parent;
                $this->atStart = true;
                break;

            case Token::CLOSE:
                $back = array_pop($this->stack);
                if (null === $back) {
                    throw new PgnSyntaxException('Unexpected ")"', $token->line);
                }
                // A comment left at the start of an empty variation has nothing to attach to.
                $this->pending = null;
                $this->pendingCommands = [];
                $this->cursor = $back;
                $this->atStart = false;
                break;

            case Token::RESULT:
                $game = $this->open($token->line);
                if ([] !== $this->stack) {
                    throw new PgnSyntaxException('Unclosed variation', $token->line);
                }
                $game->result = $token->value;
                break;
        }
    }

    private function open(int $line): Game
    {
        if (null === $this->game) {
            $this->game = new Game($line);
            $this->cursor = $this->game->root;
            $this->atStart = true;
            $this->pending = null;
            $this->pendingCommands = [];
            $this->hasMoves = false;
        }

        return $this->game;
    }

    /**
     * @throws PgnSyntaxException
     */
    private function finish(int $line): void
    {
        if (null === $this->game) {
            return;
        }
        if ([] !== $this->stack) {
            throw new PgnSyntaxException('Unclosed variation', $line);
        }
        // Comments with no move after them: the game comment.
        $root = $this->game->root;
        $root->startingComment = self::join($root->startingComment, $this->pending);
        array_push($root->commands, ...$this->pendingCommands);
        $this->games[] = $this->game;
        $this->game = null;
    }

    /**
     * The text of a comment, whitespace collapsed, and its [%name value] commands.
     *
     * @return array{string|null, list<array{string, string}>}
     */
    private static function comment(string $raw): array
    {
        $commands = [];
        if (preg_match_all(self::COMMAND, $raw, $matches, \PREG_SET_ORDER) > 0) {
            foreach ($matches as $match) {
                $commands[] = [$match[1], trim($match[2])];
            }
        }
        $text = trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace(self::COMMAND, ' ', $raw)));

        return ['' === $text ? null : $text, $commands];
    }

    private static function join(?string $existing, ?string $text): ?string
    {
        if (null === $text) {
            return $existing;
        }

        return null === $existing ? $text : $existing.' '.$text;
    }
}
