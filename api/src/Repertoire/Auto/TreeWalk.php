<?php

declare(strict_types=1);

namespace App\Repertoire\Auto;

/**
 * What {@see TreeBuilder} walks through while it grows a tree: the merged games, the user's
 * choices and removals, the explorer's answers and the rule its replies are kept by.
 *
 * @phpstan-import-type NodeStats from TreeBuilder
 * @phpstan-import-type EdgeStats from TreeBuilder
 * @phpstan-import-type Replies from ReplyRule
 */
final readonly class TreeWalk
{
    /**
     * @param array<string, NodeStats>                $nodes    node path => the games that reached it
     * @param array<string, array<string, EdgeStats>> $edges    node path => moves played there, most played first
     * @param array<string, string>                   $choices
     * @param array<string, int>                      $removed  node path => (any)
     * @param array<string, Replies>                  $explorer normalized FEN => the explorer's replies
     */
    public function __construct(
        public Tree $tree,
        public array $nodes,
        public array $edges,
        public array $choices,
        public array $removed,
        public array $explorer,
        public ReplyRule $rule,
    ) {
    }
}
