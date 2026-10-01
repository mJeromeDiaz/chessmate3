<?php

declare(strict_types=1);

namespace App\Chess\Pgn;

/**
 * A move of a parsed PGN game, or the game's root (empty SAN, before the first move). Its first
 * child continues its line; the other children are variations: alternatives to the first child,
 * played from the same position. Moves are as written in the file (SAN, not checked here).
 */
final class Node
{
    /** @var list<int> */
    public array $nags = [];

    /** Comment after the move (after the root: none, see {@see self::$startingComment}). */
    public ?string $comment = null;

    /** Comment before the move, at the start of a variation or of the game (the root's is the game comment). */
    public ?string $startingComment = null;

    /**
     * Commands embedded in the comments, e.g. ['cal', 'Gd2d4,Re7e5'] ([%cal Gd2d4,Re7e5]).
     *
     * @var list<array{string, string}>
     */
    public array $commands = [];

    /** @var list<Node> */
    public array $children = [];

    public function __construct(
        public readonly string $san = '',
        public readonly int $line = 0,
    ) {
    }

    public function isRoot(): bool
    {
        return '' === $this->san;
    }

    public function mainChild(): ?self
    {
        return $this->children[0] ?? null;
    }

    /**
     * The ends of lines below this node, in PGN order (main line first). Iterative: a main line
     * may be hundreds of moves deep.
     *
     * @return list<Node>
     */
    public function leaves(): array
    {
        $leaves = [];
        $stack = [$this];
        while ([] !== $stack) {
            $node = array_pop($stack);
            if ([] === $node->children) {
                $leaves[] = $node;
                continue;
            }
            foreach (array_reverse($node->children) as $child) {
                $stack[] = $child;
            }
        }

        return $leaves;
    }
}
