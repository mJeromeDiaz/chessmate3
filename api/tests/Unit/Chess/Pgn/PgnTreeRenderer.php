<?php

declare(strict_types=1);

namespace App\Tests\Unit\Chess\Pgn;

use App\Chess\Pgn\Node;

/**
 * A compact, readable rendering of a parsed move tree for assertions: moves without numbers,
 * NAGs as "$n", comments in braces, starting comments in angle brackets, variations in
 * parentheses. E.g. "e4 {Best.} (d4 d5) e5$2".
 */
final class PgnTreeRenderer
{
    public static function render(Node $parent): string
    {
        $out = [];
        while (null !== $main = $parent->mainChild()) {
            $out[] = self::token($main);
            foreach (\array_slice($parent->children, 1) as $variation) {
                $rest = self::render($variation);
                $out[] = '('.self::token($variation).('' === $rest ? '' : ' '.$rest).')';
            }
            $parent = $main;
        }

        return implode(' ', $out);
    }

    /**
     * Every line of the tree, root to leaf, as SAN lists.
     *
     * @param list<string> $path the moves leading to $node
     *
     * @return list<list<string>>
     */
    public static function lines(Node $node, array $path = []): array
    {
        if ([] === $node->children) {
            return [$path];
        }
        $lines = [];
        foreach ($node->children as $child) {
            array_push($lines, ...self::lines($child, [...$path, $child->san]));
        }

        return $lines;
    }

    private static function token(Node $node): string
    {
        return (null === $node->startingComment ? '' : '<'.$node->startingComment.'> ')
            .$node->san
            .implode('', array_map(static fn (int $nag): string => '$'.$nag, $node->nags))
            .(null === $node->comment ? '' : ' {'.$node->comment.'}');
    }
}
